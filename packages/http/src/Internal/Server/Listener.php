<?php

declare(strict_types=1);

namespace Thesis\Http\Internal\Server;

use Thesis\Async\Cancelled;
use Thesis\Async\Deferred;
use Thesis\Async\Group;
use Thesis\Async\Instant;
use Thesis\Async\LocalSemaphore;
use Thesis\Async\Lock;
use Thesis\Dispatcher;
use Thesis\Http\Request;
use Thesis\Http\Server\Config;
use Thesis\Http\Server\Context;
use Thesis\Http\Server\ErrorHandler;
use Thesis\Http\Server\Event;
use Thesis\Net\Address;
use Thesis\Net\Server;
use Thesis\Net\ServerTls;
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
     * @var \SplObjectStorage<Connection, Socket>
     */
    private readonly \SplObjectStorage $connections;

    /**
     * @var ?Deferred<null>
     */
    private ?Deferred $drained = null;

    private ?Instant $shutdownStarted = null;

    private int $forcedConnections = 0;

    private readonly LocalSemaphore $capacity;

    private readonly ?Dispatcher $connectionAccepted;

    private readonly ?Dispatcher $connectionClosed;

    private readonly ?Dispatcher $requestStarted;

    private readonly ?Dispatcher $requestEnded;

    /**
     * @param HandlerClosure $handler
     * @param positive-int $maxConnections
     */
    public function __construct(
        private readonly Server $socketListener,
        private readonly \Closure $handler,
        private readonly Config $config,
        private readonly ?ServerTls $tls,
        int $maxConnections,
        private readonly Dispatcher $dispatcher,
        private readonly ErrorHandler $errorHandler,
    ) {
        $this->connections = new \SplObjectStorage();
        $this->capacity = new LocalSemaphore($maxConnections);
        $this->connectionAccepted = $dispatcher->for(Event\ConnectionAccepted::class);
        $this->connectionClosed = $dispatcher->for(Event\ConnectionClosed::class);
        $this->requestStarted = $dispatcher->for(Event\RequestStarted::class);
        $this->requestEnded = $dispatcher->for(Event\RequestEnded::class);
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
            $this->dispatcher->dispatch(new Event\ServerStopped(
                address: $this->socketListener->address,
                forcedConnections: $this->forcedConnections,
            ));
        }
    }

    public function stop(?Instant $shutdownStarted = null): void
    {
        $this->shutdownStarted ??= $shutdownStarted ?? Instant::now();
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
            $this->dispatcher->dispatch(new Event\ServerStarted(
                address: $this->socketListener->address,
                tls: $this->tls !== null,
            ));
            $this->socketListener->awaitClose();
            $this->dispatcher->dispatch(new Event\ServerStopping(
                address: $this->socketListener->address,
                connections: $this->connections->count(),
                timeout: $this->config->shutdownTimeout,
            ));
            $acceptor->cancel();

            if ($this->connections->count() > 0) {
                $this->drained = new Deferred();
                \assert($this->shutdownStarted !== null);
                $remaining = $this->config->shutdownTimeout->sub(Instant::now()->since($this->shutdownStarted));
                if ($remaining > sec(0)) {
                    timeoutOr($remaining, $this->drained->await(...));
                }
                foreach ($this->connections as $connection) {
                    if (!$this->connections[$connection]->isClosed) {
                        ++$this->forcedConnections;
                    }
                }
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
                requestStarted: $this->requestStarted,
                requestEnded: $this->requestEnded,
                dispatcher: $this->dispatcher,
            );
            $this->connections[$connection] = $socket;
            $connection->task = $group->spawn(fn() => $this->serveConnection($socket, $connection, $permit));
        }
    }

    private function serveConnection(Socket $socket, Connection $connection, Lock $permit): void
    {
        try {
            $this->connectionAccepted?->dispatch(new Event\ConnectionAccepted(
                localAddress: $socket->localAddress,
                remoteAddress: $socket->remoteAddress,
            ));

            if ($socket->isClosed) {
                return;
            }

            $tls = $this->tls;
            if ($tls !== null && !$this->negotiateTls($socket, $tls)) {
                return;
            }

            $this->handleRequests($socket, $connection);
        } finally {
            $connection->close();
            unset($this->connections[$connection]);
            $permit->release();

            if ($this->connections->count() === 0) {
                $this->drained?->complete();
            }
            $this->connectionClosed?->dispatch(new Event\ConnectionClosed(
                localAddress: $socket->localAddress,
                remoteAddress: $socket->remoteAddress,
            ));
        }
    }

    private function negotiateTls(Socket $socket, ServerTls $tls): bool
    {
        try {
            timeout($this->config->tlsHandshakeTimeout, static fn() => $socket->enableTls($tls));

            $protocol = $socket->tls?->alpnProtocol;
            if ($protocol !== null && $protocol !== 'http/1.1') {
                throw new TlsFailed('The negotiated TLS application protocol is not HTTP/1.1.');
            }
        } catch (\Throwable $error) {
            if ($error instanceof Cancelled) {
                throw $error;
            }

            if ($error instanceof SocketClosed && $this->socketListener->isClosed) {
                return false;
            }

            $this->dispatcher->dispatch(new Event\TlsHandshakeFailed(
                localAddress: $socket->localAddress,
                remoteAddress: $socket->remoteAddress,
                error: $error,
            ));

            return false;
        }

        return true;
    }

    private function handleRequests(Socket $socket, Connection $connection): void
    {
        try {
            $context = new Context(
                localAddress: $socket->localAddress,
                remoteAddress: $socket->remoteAddress,
                tls: $socket->tls,
                errorHandler: $this->errorHandler,
                dispatcher: $this->dispatcher,
            );
            $connection->run(
                handler: fn(Request $request) => ($this->handler)($request, $context),
                scheme: $socket->tls === null ? 'http' : 'https',
            );
        } catch (Cancelled $cancelled) {
            throw $cancelled;
        } catch (\Throwable $error) {
            if (!($error instanceof SocketClosed && $this->socketListener->isClosed)) {
                $this->dispatcher->dispatch(new Event\ConnectionHandlingFailed(
                    localAddress: $socket->localAddress,
                    remoteAddress: $socket->remoteAddress,
                    error: $error,
                ));
            }
        }
    }
}
