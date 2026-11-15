<?php

declare(strict_types=1);

namespace Thesis\Amqp;

use Thesis\Amqp\Internal\Connection;
use Thesis\Async\Deferred;
use Thesis\Async\Group;
use Thesis\Async\TimedOut;
use Thesis\Io\IoException;
use function Thesis\Async\timeout;
use function Thesis\Async\timeoutOr;
use function Thesis\Net\connect;
use function Thesis\Time\ns;

/**
 * A connection to RabbitMQ that reconnects. A passive object until {@see run()} (ADR 0001):
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
    private const int MIN_BACKOFF_NS = 100_000_000;
    private const int MAX_BACKOFF_NS = 30_000_000_000;

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

        $connection = $this->connect();
        $this->running = true;
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
            } catch (ConnectionClosed $exception) {
                if ($this->closing || !$this->config->reconnect) {
                    throw $exception;
                }
                // Lost while opening the channel: the next connection will do.
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
        while (true) {
            $connection->run();

            if ($this->closing) {
                return;
            }

            if (!$this->config->reconnect) {
                $this->next->fail($connection->closed ?? new ConnectionClosed('The connection is lost.'));

                return;
            }

            $connection = $this->reconnect();

            if ($connection === null) {
                return;
            }

            $this->up($connection);
        }
    }

    /**
     * @return ?Connection null if the client is closed meanwhile
     */
    private function reconnect(): ?Connection
    {
        $backoff = self::MIN_BACKOFF_NS;

        while (true) {
            // Full jitter: clients that lost one broker do not come back to it all at once.
            timeoutOr(ns(random_int(intdiv($backoff, 2), $backoff)), $this->stopped->await(...));

            if ($this->closing) {
                return null;
            }

            try {
                return $this->connect();
            } catch (ConnectionFailed) {
                $backoff = min(self::MAX_BACKOFF_NS, $backoff * 2);
            }
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
            [$name, $port] = self::address($host, $defaultPort);

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

    /**
     * @param non-empty-string $host
     * @return array{non-empty-string, int<0, 65535>}
     */
    private static function address(string $host, int $defaultPort): array
    {
        if (preg_match('~^(\[[^]]+]|[^:]+)(?::(\d+))?$~', $host, $parts) !== 1) {
            throw new \InvalidArgumentException(
                message: "The host \"{$host}\" is malformed: use host, host:port or [ipv6]:port.",
            );
        }

        /** @var non-empty-string $name */
        $name = $parts[1];
        /** @var int<0, 65535> $port */
        $port = isset($parts[2]) ? min(65535, (int) $parts[2]) : $defaultPort;

        return [$name, $port];
    }
}
