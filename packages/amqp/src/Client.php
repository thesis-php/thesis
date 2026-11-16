<?php

declare(strict_types=1);

namespace Thesis\Amqp;

use Thesis\Amqp\Internal\Connection;
use Thesis\Async\Backoff;
use Thesis\Async\Deferred;
use Thesis\Async\Group;
use Thesis\Async\Instant;
use Thesis\Async\TimedOut;
use Thesis\Io\IoException;
use function Thesis\Amqp\Internal\address;
use function Thesis\Async\timeout;
use function Thesis\Async\timeoutOr;
use function Thesis\Net\connect;
use function Thesis\Time\ms;
use function Thesis\Time\sec;
use function Thesis\Time\toNs;

/**
 * A connection to RabbitMQ that reconnects. A passive object until {@see run()} (agents/clients.md):
 *
 *     $client = new Client(Config::fromUri('amqp://guest:guest@localhost:5672/'));
 *
 *     group(static function (Group $group) use ($client) {
 *         $client->run($group);
 *
 *         try {
 *             $channel = $client->channel();
 *             // ...
 *         } finally {
 *             shield(static fn() => timeout(sec(5), $client->close(...)));
 *         }
 *     });
 *
 * A lost connection takes its channels with it: they throw {@see ConnectionClosed}, and so do their consumers
 * and the publishes waiting for a confirm. The client connects again in the background, {@see channel()}
 * waits for it, and the application opens its channels and consumers anew. Nothing is recovered behind its back:
 * delivery tags start anew, and a declaration or a consumer would be repeated in an order it does not control.
 *
 * @api
 */
final class Client
{
    private bool $running = false;

    private bool $closing = false;

    private ?Connection $connection = null;

    /**
     * The next connection that comes up, or why none will.
     *
     * @var Deferred<Connection>
     */
    private Deferred $next;

    /**
     * Completed by {@see close()}: ends the wait between reconnects.
     *
     * @var Deferred<null>
     */
    private readonly Deferred $stopped;

    /**
     * Does no I/O.
     */
    public function __construct(
        public readonly Config $config,
    ) {
        $this->next = new Deferred();
        $this->stopped = new Deferred();
    }

    /**
     * Connects to the first host of the config that accepts, performs the handshake in the calling fiber
     * and returns: the client is ready. The reader, the heartbeats and the reconnects run as daemons of $group,
     * so the client works until {@see close()} or the end of the group.
     *
     * @throws ConnectionFailed
     * @throws \LogicException if the client is running already
     */
    public function run(Group $group): void
    {
        if ($this->running) {
            throw new \LogicException('The client is running already.');
        }

        // Before the first wait: a concurrent run() must not open a second connection.
        $this->running = true;

        try {
            $connection = $this->connect();
        } catch (\Throwable $exception) {
            $this->running = false;

            throw $exception;
        }

        if ($this->closing) {
            $connection->terminate(new ConnectionClosed('The client is closed.', ReplyCode::ReplySuccess));

            return;
        }

        $this->up($connection);
        $group->daemon(fn() => $this->supervise($connection));
    }

    /**
     * Opens a channel. While the client reconnects, waits for the connection: bound it with `timeout()`.
     *
     * @throws ConnectionClosed if the client is closed, or the connection is lost and reconnects are off
     * @throws AmqpException
     * @throws \LogicException if {@see run()} has not been called
     */
    public function channel(): Channel
    {
        while (true) {
            $connection = $this->connection();

            try {
                return $connection->openChannel();
            } catch (AmqpException $exception) {
                // Lost while opening the channel: the next connection will do. Any other failure is thrown,
                // retrying it on a live connection would spin.
                if ($connection->closed === null || $this->closing || !$this->config->reconnect) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Closes gracefully: cancels the consumers (their iterations end), waits for the confirms of the publishes
     * in flight, closes the connection with the server. Bound it with `timeout()` and `shield()`.
     * The daemons end by themselves afterwards. Later calls do nothing.
     */
    public function close(): void
    {
        if (!$this->running || $this->closing) {
            return;
        }

        $this->closing = true;
        $this->stopped->complete();

        try {
            $this->connection?->close();
        } finally {
            $this->next->fail(new ConnectionClosed('The client is closed.', ReplyCode::ReplySuccess));
        }
    }

    /**
     * @throws ConnectionClosed
     * @throws \LogicException
     */
    private function connection(): Connection
    {
        if (!$this->running) {
            throw new \LogicException('The client is not running: call run() first.');
        }

        if ($this->closing) {
            throw new ConnectionClosed('The client is closed.', ReplyCode::ReplySuccess);
        }

        $connection = $this->connection;

        if ($connection !== null && $connection->closed === null) {
            return $connection;
        }

        return $this->next->await();
    }

    private function up(Connection $connection): void
    {
        $this->connection = $connection;
        $next = $this->next;
        $this->next = new Deferred();
        $next->complete($connection);
    }

    private function supervise(Connection $connection): void
    {
        $backoff = new Backoff(initial: ms(100), max: sec(30));

        while (true) {
            $openedAt = Instant::now();
            $connection->run();

            if ($this->closing) {
                return;
            }

            if (!$this->config->reconnect) {
                $this->next->fail($connection->closed ?? new ConnectionClosed('The connection is lost.'));

                return;
            }

            // Only a connection that has lived starts the backoff anew: a broker that accepts and drops at once
            // (a limit of connections, a node going down) must not be retried every 100 ms.
            if (toNs(Instant::now()->since($openedAt)) > toNs(sec(30))) {
                $backoff->reset();
            }

            $connection = $this->reconnect($backoff);

            if ($connection === null) {
                return;
            }

            $this->up($connection);
        }
    }

    /**
     * @return ?Connection null if the client is closed meanwhile
     */
    private function reconnect(Backoff $backoff): ?Connection
    {
        while (true) {
            timeoutOr($backoff->next(), $this->stopped->await(...));

            if ($this->closing) {
                return null;
            }

            try {
                $connection = $this->connect();
            } catch (ConnectionFailed) {
                continue;
            }

            // close() has come during the handshake: it could not see this connection.
            if ($this->closing) { // @phpstan-ignore if.alwaysFalse (close() runs while connect() waits)
                $connection->terminate(new ConnectionClosed('The client is closed.', ReplyCode::ReplySuccess));

                return null;
            }

            return $connection;
        }
    }

    /**
     * @throws ConnectionFailed
     */
    private function connect(): Connection
    {
        $defaultPort = $this->config->tls === null ? 5672 : 5671;
        $failures = [];
        $last = null;

        foreach ($this->config->hosts as $host) {
            [$name, $port] = address($host, $defaultPort);

            try {
                return timeout(
                    $this->config->connectTimeout,
                    fn() => Connection::open(connect($name, $port, $this->config->tls), $this->config),
                );
            } catch (IoException|AmqpException|TimedOut $exception) {
                $last = $exception;
                $failures[] = "{$host}: {$exception->getMessage()}";
            }
        }

        throw new ConnectionFailed(
            message: 'Failed to connect to RabbitMQ. ' . implode(' ', $failures),
            previous: $last,
        );
    }
}
