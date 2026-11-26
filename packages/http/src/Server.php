<?php

declare(strict_types=1);

namespace Thesis\Http;

use Thesis\Async\Group;
use Thesis\Async\Instant;
use Thesis\Async\Service;
use Thesis\Async\Task;
use Thesis\Dispatcher;
use Thesis\Http\Server\Config;
use Thesis\Http\Server\Context;
use Thesis\Http\Server\DefaultErrorHandler;
use Thesis\Http\Server\ErrorHandler;
use Thesis\Http\Server\Internal\Listener;
use Thesis\Http\Server\TcpEndpoint;
use Thesis\Http\Server\UnixEndpoint;
use Thesis\Net\Address;
use Thesis\Net\ListenFailed;
use function Thesis\Async\checkpoint;
use function Thesis\Async\group;
use function Thesis\Async\onCancel;
use function Thesis\Async\shield;
use function Thesis\Net\listen;
use function Thesis\Net\listenUnix;

/**
 * An HTTP/1 server serving one or more TCP or Unix endpoints.
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

    /**
     * @var list<Listener>
     */
    private array $listeners = [];

    /**
     * @var list<Address> bound addresses in configuration order
     */
    public array $addresses {
        get {
            $addresses = [];
            foreach ($this->listeners as $listener) {
                if (($address = $listener->address) !== null) {
                    $addresses[] = $address;
                }
            }

            return $addresses;
        }
    }

    /**
     * The bound address while listening, including the port assigned by the OS.
     */
    public ?Address $address {
        get => ($this->listeners[0] ?? null)?->address;
    }

    /**
     * @var HandlerClosure
     */
    private readonly \Closure $handler;

    /**
     * @param Handler $handler
     */
    public function __construct(
        public readonly Config $config,
        callable $handler,
        private readonly Dispatcher $dispatcher = new Dispatcher(),
        private readonly ErrorHandler $errorHandler = new DefaultErrorHandler(),
    ) {
        $this->handler = $handler(...);
    }

    /**
     * Starts serving in the supplied group and returns once its daemon owns shutdown.
     * The group joins active exchanges on shutdown, within the configured deadline.
     *
     * @throws ListenFailed if the endpoint cannot be bound
     */
    public function start(Group $group): void
    {
        if ($this->listeners !== []) {
            throw new \LogicException('The server has already started.');
        }

        try {
            foreach ($this->config->endpoints as $endpoint) {
                $this->listeners[] = $this->bind($endpoint);
            }
            $this->task = $group->daemon($this->run(...));

            foreach ($this->listeners as $listener) {
                while (!$listener->isReady && !$this->task->isDone) {
                    checkpoint();
                }
                if (!$listener->isReady) {
                    $this->task->await();
                }
            }
        } catch (\Throwable $error) {
            $this->closeListeners();
            if ($this->task !== null) {
                $this->task->cancel();

                try {
                    shield($this->task->await(...));
                } catch (\Throwable) {
                    // Report the original startup failure after the daemon has released its listeners.
                }
            }
            $this->listeners = [];
            $this->task = null;

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
        $this->closeListeners();
        $this->task?->await();
    }

    private function closeListeners(): void
    {
        $started = Instant::now();
        foreach ($this->listeners as $listener) {
            $listener->stop($started);
        }
    }

    private function run(): void
    {
        $unsubscribe = onCancel($this->closeListeners(...));

        try {
            shield(fn() => group(function (Group $group) {
                foreach ($this->listeners as $listener) {
                    $group->spawn($listener->run(...));
                }
            }));
        } finally {
            $unsubscribe();
        }
    }

    private function bind(TcpEndpoint|UnixEndpoint $endpoint): Listener
    {
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

        return new Listener(
            socketListener: $socketListener,
            handler: $this->handler,
            config: $this->config,
            tls: $endpoint->tls ?? $this->config->tls,
            maxConnections: $endpoint->maxConnections ?? $this->config->maxConnections,
            dispatcher: $this->dispatcher,
            errorHandler: $this->errorHandler,
        );
    }
}
