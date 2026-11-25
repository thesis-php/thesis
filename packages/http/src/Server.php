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
use function Thesis\Async\checkpoint;
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

    private ?Listener $listener = null;

    /**
     * The bound address while listening, including the port assigned by the OS.
     */
    public ?Address $address {
        get => $this->listener?->address;
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
        $socketListener = match (true) {
            $endpoint instanceof TcpEndpoint => listen(
                host: $endpoint->address->ip,
                port: $endpoint->address->port,
                backlog: $endpoint->backlog,
                reusePort: $endpoint->reusePort,
                noDelay: $endpoint->noDelay,
            ),
            $endpoint instanceof UnixEndpoint => listenUnix($endpoint->path, $endpoint->backlog),
        };

        try {
            $httpListener = new Listener(
                socketListener: $socketListener,
                handler: $this->handler,
                config: $this->config,
                onError: $this->onError,
                errorHandler: $this->errorHandler,
            );
            $this->listener = $httpListener;
            $task = $group->daemon($httpListener->run(...));
            $this->task = $task;

            // A group may finish as soon as start() returns. A daemon cancelled before it starts never runs its finally.
            while (!$httpListener->isReady && !$task->isDone) {
                checkpoint();
            }

            if (!$httpListener->isReady) {
                $task->await();
            }
        } catch (\Throwable $error) {
            $socketListener->close();
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
        $this->listener?->stop();
        $this->task?->await();
    }
}
