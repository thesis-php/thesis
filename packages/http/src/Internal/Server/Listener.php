<?php

declare(strict_types=1);

namespace Thesis\Http\Internal\Server;

use Thesis\Async\Cancelled;
use Thesis\Async\Deferred;
use Thesis\Async\Group;
use Thesis\Async\LocalSemaphore;
use Thesis\Http\Request;
use Thesis\Http\Server\Config;
use Thesis\Http\Server\Context;
use Thesis\Http\Server\ErrorHandler;
use Thesis\Net\Server;
use Thesis\Net\SocketClosed;
use Thesis\Net\TlsFailed;
use Time\Duration;
use function Thesis\Async\group;
use function Thesis\Async\timeout;
use function Thesis\Async\timeoutOr;
use function Thesis\Time\sec;

/**
 * @internal
 * @phpstan-import-type HandlerClosure from \Thesis\Http\Server
 */
final class Listener
{
    /**
     * @var array<int, ConnectionState>
     */
    private array $connections = [];

    /**
     * @var Deferred<null>
     */
    private readonly Deferred $drained;

    private readonly LocalSemaphore $capacity;

    /**
     * @param HandlerClosure $handler
     * @param (\Closure(\Throwable): void)|null $onError
     */
    public function __construct(
        private readonly Server $listener,
        private readonly \Closure $handler,
        private readonly Config $config,
        private readonly ?\Closure $onError,
        private readonly ErrorHandler $errorHandler,
    ) {
        $this->drained = new Deferred();
        $this->capacity = new LocalSemaphore($config->maxConnections);
    }

    public function run(): void
    {
        group(function (Group $group) {
            $idle = self::deadlines($group, $this->config->idleTimeout);
            $writes = ($this->config->writeTimeout <=> $this->config->idleTimeout) === 0
                ? $idle
                : self::deadlines($group, $this->config->writeTimeout);
            $acceptor = $group->spawn(fn() => $this->accept($group, $idle, $writes));

            try {
                $this->listener->awaitClose();
                $acceptor->cancel();

                foreach ($this->connections as $connection) {
                    $connection->stopIfIdle();
                }

                if ($this->connections !== []) {
                    timeoutOr($this->config->shutdownTimeout, $this->drained->await(...));
                }
            } finally {
                $this->listener->close();
                $group->cancelTasks();

                foreach ($this->connections as $connection) {
                    $connection->socket->close();
                }
            }
        });
    }

    private static function deadlines(Group $group, Duration $timeout): ?SocketDeadlines
    {
        return $timeout > sec(0) ? new SocketDeadlines($group, $timeout) : null;
    }

    private function accept(Group $group, ?SocketDeadlines $idle, ?SocketDeadlines $writes): void
    {
        while (!$this->listener->isClosed) {
            $permit = $this->capacity->acquire();
            $socket = $this->listener->accept();
            if ($socket === null) {
                $permit->release();

                return;
            }

            $connection = new ConnectionState($this->listener, $socket, $idle, $writes);
            $id = spl_object_id($connection);
            $this->connections[$id] = $connection;
            $group->spawn(function () use ($connection, $id, $permit) {
                try {
                    $this->serveConnection($connection);
                } finally {
                    $connection->socket->close();
                    unset($this->connections[$id]);
                    $permit->release();

                    if ($this->listener->isClosed && $this->connections === []) {
                        $this->drained->complete();
                    }
                }
            });
        }
    }

    private function serveConnection(ConnectionState $connection): void
    {
        if ($connection->isStopping) {
            return;
        }

        $socket = $connection->socket;
        $tls = $this->config->tls;

        try {
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
            Connection::serve(
                input: $socket,
                output: $socket,
                handler: fn(Request $request) => ($this->handler)($request, $context),
                config: $this->config,
                state: $connection,
                errorHandler: $this->errorHandler,
            );
        } catch (Cancelled $cancelled) {
            throw $cancelled;
        } catch (\Throwable $error) {
            if ($this->onError !== null && !($error instanceof SocketClosed && $connection->isStopping)) {
                ($this->onError)($error);
            }
        }
    }
}
