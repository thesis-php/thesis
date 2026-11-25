<?php

declare(strict_types=1);

namespace Thesis;

use Thesis\Dispatcher\Internal\Listener;

/**
 * Calls the listeners of an event one by one, in the order they are given. A listener gets the events
 * of the type of its first parameter: a class or an interface, a union or an intersection of them,
 * or every event for `object`, `mixed` and no type. The second argument of a listener is this dispatcher,
 * for the events the listener dispatches itself. An object with several listeners
 * is a {@see Dispatcher\Subscriber}, given among them.
 *
 * @api
 */
final class Dispatcher
{
    /**
     * @var list<Listener>
     */
    private readonly array $listeners;

    /**
     * Filled on the first event of each class: the classes of the events are not known in advance,
     * a listener of an interface gets the events of classes that are not loaded yet.
     *
     * @var array<class-string, list<\Closure(object, self): void>>
     */
    private array $listenersByClass = [];

    /**
     * @param iterable<(callable(never, self): void)|Dispatcher\Subscriber> $listeners called in the order
     *                                                                                 of iteration, the keys
     *                                                                                 are ignored
     * @throws \InvalidArgumentException if a listener cannot take an event or is a generator
     */
    public function __construct(iterable $listeners = [])
    {
        $this->listeners = Listener::all($listeners);
    }

    /**
     * If a listener suspends the fiber, this call suspends with it. This call propagates the listener's exception,
     * the listeners after it are not called. An event dispatched by a listener goes to its listeners at once,
     * before the next listener of $event is called.
     */
    public function dispatch(object $event): void
    {
        // without the table every event resolves its listeners again and gets the same ones: only slower
        // @infection-ignore Coalesce
        foreach ($this->listenersByClass[$event::class] ?? $this->resolve($event::class) as $listener) {
            $listener($event, $this);
        }
    }

    /**
     * Whether any listener gets the events of $class: to skip building an event that nobody listens to.
     *
     * @param class-string $class
     */
    public function listens(string $class): bool
    {
        return ($this->listenersByClass[$class] ?? $this->resolve($class)) !== [];
    }

    /**
     * Returns this dispatcher if any listener accepts any of the given event classes, or null otherwise.
     *
     * @no-named-arguments
     * @param class-string ...$classes
     */
    public function for(string ...$classes): ?static
    {
        return array_any($classes, $this->listens(...)) ? $this : null;
    }

    /**
     * @param class-string $class
     * @return list<\Closure(object, self): void>
     */
    private function resolve(string $class): array
    {
        $listeners = [];

        foreach ($this->listeners as $listener) {
            if ($listener->accepts($class)) {
                $listeners[] = $listener->fn;
            }
        }

        return $this->listenersByClass[$class] = $listeners;
    }
}
