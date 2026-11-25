<?php

declare(strict_types=1);

namespace Thesis\Http;

use Thesis\Async\Group;
use Thesis\Async\Service;
use Thesis\Async\Task;
use Thesis\Http\Internal\Server\Listener;
use Thesis\Http\Server\Config;
use Thesis\Http\Server\Context;
use Thesis\Http\Server\DefaultErrorHandler;
use Thesis\Http\Server\ErrorHandler;
use Thesis\Http\Server\TcpEndpoint;
use Thesis\Http\Server\UnixEndpoint;
use Thesis\Net\Address;
use Thesis\Net\ListenFailed;
use Thesis\Net\Server as SocketListener;
use function Thesis\Async\checkpoint;
use function Thesis\Async\onCancel;
use function Thesis\Async\shield;
use function Thesis\Net\listen;
use function Thesis\Net\listenUnix;

/**
 * An HTTP/1 server owning one TCP or Unix listener.
 *
 * @api
 * @phpstan-type Handler callable(Request, Context): Response
 * @phpstan-type HandlerClosure \Closure(Request, Context): Response
 */
final class Server implements Service
{
    /**
     * @var ?Task<void>
     */
    private ?Task $task = null;

    private ?SocketListener $listener = null;

    /**
     * The bound address while listening, including the port assigned by the OS.
     */
    public ?Address $address {
        get => $this->listener?->isClosed === false ? $this->listener->address : null;
    }

    /**
     * @var HandlerClosure
     */
    private readonly \Closure $handler;

    /**
     * @var (\Closure(\Throwable): void)|null
     */
    private readonly ?\Closure $onError;

    /**
     * @param Handler $handler
     * @param ?callable(\Throwable): void $onError observes connection failures. An exception it throws fails the group.
     */
    public function __construct(
        public readonly Config $config,
        callable $handler,
        ?callable $onError = null,
        private readonly ErrorHandler $errorHandler = new DefaultErrorHandler(),
    ) {
        $this->handler = $handler(...);
        $this->onError = $onError === null ? null : $onError(...);
    }

    /**
     * Starts serving in the supplied group and returns once its daemon owns shutdown.
     * The group joins active exchanges on shutdown, within the configured deadline.
     *
     * @throws ListenFailed if the endpoint cannot be bound
     */
    public function start(Group $group): void
    {
        if ($this->listener !== null) {
            throw new \LogicException('The server has already started.');
        }

        $endpoint = $this->config->endpoint;
        $listener = match (true) {
            $endpoint instanceof TcpEndpoint => listen(
                host: $endpoint->address->ip,
                port: $endpoint->address->port,
                backlog: $endpoint->backlog,
                reusePort: $endpoint->reusePort,
                noDelay: $endpoint->noDelay,
            ),
            $endpoint instanceof UnixEndpoint => listenUnix($endpoint->path, $endpoint->backlog),
        };
        $this->listener = $listener;
        $ready = false;

        try {
            $driver = new Listener(
                listener: $listener,
                handler: $this->handler,
                config: $this->config,
                onError: $this->onError,
                errorHandler: $this->errorHandler,
            );
            $task = $group->daemon(static function () use ($listener, $driver, &$ready) {
                $unsubscribe = onCancel($listener->close(...));

                try {
                    $ready = true;
                    shield($driver->run(...));
                } finally {
                    $unsubscribe();
                    $listener->close();
                }
            });

            $this->task = $task;

            // A group may finish as soon as start() returns. A daemon cancelled before it starts never runs its finally.
            while (!$ready && !$task->isDone) {
                checkpoint();
            }

            if (!$ready) {
                $task->await();
            }
        } catch (\Throwable $error) {
            $listener->close();
            $this->listener = null;

            throw $error;
        }
    }

    /**
     * Stops accepting connections and waits for the server and its active exchanges to finish.
     * Returns immediately before startup.
     * Call from outside request handlers.
     *
     * @throws \Throwable if the serving task fails or was cancelled
     */
    public function stop(): void
    {
        $this->listener?->close();
        $this->task?->await();
    }
}
