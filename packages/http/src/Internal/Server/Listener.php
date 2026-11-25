<?php

declare(strict_types=1);

namespace Thesis\Http\Internal\Server;

use Thesis\Async\Cancelled;
use Thesis\Async\Deferred;
use Thesis\Async\Group;
use Thesis\Async\LocalSemaphore;
use Thesis\Async\Lock;
use Thesis\Http\Request;
use Thesis\Http\Server\Config;
use Thesis\Http\Server\Context;
use Thesis\Http\Server\ErrorHandler;
use Thesis\Net\Address;
use Thesis\Net\Server;
use Thesis\Net\Socket;
use Thesis\Net\SocketClosed;
use Thesis\Net\TlsFailed;
use Time\Duration;
use function Thesis\Async\group;
use function Thesis\Async\onCancel;
use function Thesis\Async\shield;
use function Thesis\Async\timeout;
use function Thesis\Async\timeoutOr;
use function Thesis\Time\sec;

/**
 * @internal
 * @phpstan-import-type HandlerClosure from \Thesis\Http\Server
 */
final class Listener
{
    public ?Address $address {
        get => $this->socketListener->isClosed ? null : $this->socketListener->address;
    }

    public private(set) bool $isReady = false;

    /**
     * @var \SplObjectStorage<Connection, null>
     */
    private readonly \SplObjectStorage $connections;

    /**
     * @var ?Deferred<null>
     */
    private ?Deferred $drained = null;

    private readonly LocalSemaphore $capacity;

    /**
     * @param HandlerClosure $handler
     * @param (\Closure(\Throwable): void)|null $onError
     */
    public function __construct(
        private readonly Server $socketListener,
        private readonly \Closure $handler,
        private readonly Config $config,
        private readonly ?\Closure $onError,
        private readonly ErrorHandler $errorHandler,
    ) {
        $this->connections = new \SplObjectStorage();
        $this->capacity = new LocalSemaphore($config->maxConnections);
    }

    public function run(): void
    {
        // Cancellation closes the listening socket while active exchanges get their shutdown deadline.
        $unsubscribe = onCancel($this->stop(...));

        try {
            $this->isReady = true;
            shield(fn() => group($this->serve(...)));
        } finally {
            $unsubscribe();
            $this->socketListener->close();
        }
    }

    public function stop(): void
    {
        $this->socketListener->close();

        foreach ($this->connections as $connection) {
            $connection->stop();
        }
    }

    private function serve(Group $group): void
    {
        $idle = self::deadlines($group, $this->config->idleTimeout);
        $writes = ($this->config->writeTimeout <=> $this->config->idleTimeout) === 0
            ? $idle
            : self::deadlines($group, $this->config->writeTimeout);
        $acceptor = $group->spawn(fn() => $this->accept($group, $idle, $writes));

        try {
            $this->socketListener->awaitClose();
            $acceptor->cancel();

            if ($this->connections->count() > 0) {
                $this->drained = new Deferred();
                timeoutOr($this->config->shutdownTimeout, $this->drained->await(...));
            }
        } finally {
            $this->socketListener->close();
            $group->cancelTasks();

            foreach ($this->connections as $connection) {
                $connection->close();
            }
        }
    }

    private static function deadlines(Group $group, Duration $timeout): ?SocketDeadlines
    {
        return $timeout > sec(0) ? new SocketDeadlines($group, $timeout) : null;
    }

    private function accept(Group $group, ?SocketDeadlines $idle, ?SocketDeadlines $writes): void
    {
        while (!$this->socketListener->isClosed) {
            $permit = $this->capacity->acquire();
            $socket = $this->socketListener->accept();
            if ($socket === null) {
                $permit->release();

                return;
            }

            $connection = new Connection(
                input: $socket,
                output: $socket,
                config: $this->config,
                errorHandler: $this->errorHandler,
                idle: $idle,
                writes: $writes,
            );
            $this->connections[$connection] = null;
            $group->spawn(fn() => $this->serveConnection($socket, $connection, $permit));
        }
    }

    private function serveConnection(Socket $socket, Connection $connection, Lock $permit): void
    {
        try {
            if ($socket->isClosed) {
                return;
            }

            $tls = $this->config->tls;
            if ($tls !== null) {
                timeout($this->config->tlsHandshakeTimeout, static fn() => $socket->enableTls($tls));

                $protocol = $socket->tls?->alpnProtocol;
                if ($protocol !== null && $protocol !== 'http/1.1') {
                    throw new TlsFailed('The negotiated TLS application protocol is not HTTP/1.1.');
                }
            }

            $context = new Context(
                localAddress: $socket->localAddress,
                remoteAddress: $socket->remoteAddress,
                tls: $socket->tls,
                errorHandler: $this->errorHandler,
            );
            $connection->run(
                handler: fn(Request $request) => ($this->handler)($request, $context),
                scheme: $socket->tls === null ? 'http' : 'https',
            );
        } catch (Cancelled $cancelled) {
            throw $cancelled;
        } catch (\Throwable $error) {
            if ($this->onError !== null && !($error instanceof SocketClosed && $this->socketListener->isClosed)) {
                ($this->onError)($error);
            }
        } finally {
            $connection->close();
            unset($this->connections[$connection]);
            $permit->release();

            if ($this->connections->count() === 0) {
                $this->drained?->complete();
            }
        }
    }
}
