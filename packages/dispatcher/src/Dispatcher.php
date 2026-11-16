<?php

declare(strict_types=1);

namespace Thesis;

use Thesis\Dispatcher\Internal\Listener;

/**
 * Calls the listeners of an event one by one, in the order they are given. A listener gets the events
 * of the type of its first parameter: a class or an interface, a union or an intersection of them,
 * or every event for `object`, `mixed` and no type.
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
     * @var array<class-string, list<\Closure(object): void>>
     */
    private array $listenersByClass = [];

    /**
     * @param iterable<callable(never): void> $listeners called in the order of iteration, the keys are ignored
     * @throws \InvalidArgumentException if a listener cannot take an event or is a generator
     */
    public function __construct(iterable $listeners = [])
    {
        $this->listeners = array_map(Listener::of(...), iterator_to_array($listeners, preserve_keys: false));
    }

    /**
     * A listener may suspend the fiber: then so does this call. What a listener throws is thrown here,
     * the listeners after it are not called.
     */
    public function dispatch(object $event): void
    {
        foreach ($this->listenersByClass[$event::class] ?? $this->resolve($event::class) as $listener) {
            $listener($event);
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
     * @param class-string $class
     * @return list<\Closure(object): void>
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
