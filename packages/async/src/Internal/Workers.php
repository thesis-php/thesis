<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Thesis\Async\Group;
use Thesis\Async\LocalMutex;
use function Thesis\Async\synchronized;

/**
 * The worker pool behind `Thesis\Async\each()`: at most $concurrency tasks, each takes the next item
 * once it is done with the previous one.
 *
 * Workers are started lazily: a new one only when the item just taken leaves no other worker free,
 * so a short iterable does not start $concurrency tasks.
 *
 * The iterator is shared, so it is advanced under a mutex: a generator may suspend (fetch the next page),
 * and another worker must not resume it meanwhile.
 *
 * @internal
 * @template K
 * @template T
 */
final class Workers
{
    /** @var \Generator<K, T> */
    private readonly \Generator $items;

    /** @var \Closure(T, K): mixed */
    private readonly \Closure $fn;

    private readonly LocalMutex $mutex;

    private bool $started = false;

    private int $workers = 0;

    /** workers that are inside $fn */
    private int $busy = 0;

    /**
     * @param iterable<K, T> $items
     * @param callable(T, K): mixed $fn
     * @param positive-int $concurrency
     */
    public function __construct(
        private readonly Group $group,
        iterable $items,
        callable $fn,
        private readonly int $concurrency,
    ) {
        $this->items = (static fn() => yield from $items)();
        $this->fn = $fn(...);
        $this->mutex = new LocalMutex();
    }

    public function start(): void
    {
        ++$this->workers;
        $this->group->spawn($this->work(...));
    }

    private function work(): void
    {
        while (($next = $this->take()) !== null) {
            // Started before $fn, which may wait: the next item is taken while this one is being processed.
            // A worker that turns out to be unneeded finds the items exhausted and returns.
            if (++$this->busy === $this->workers && $this->workers < $this->concurrency) {
                $this->start();
            }

            ($this->fn)($next[0], $next[1]);
            --$this->busy;
        }
    }

    /**
     * @return ?array{T, K} null once the items are exhausted
     */
    private function take(): ?array
    {
        return synchronized($this->mutex, function (): ?array {
            // After a failure the group cancels its tasks, but a lock handed over just before wins over cancellation,
            // and a $fn that catches Cancelled returns normally: nothing more is pulled then.
            Scope::current()->throwIfCancelled();

            // A generator is started by valid(): next() is called only from the second item on,
            // so an item is pulled when it is taken, not ahead of it.
            if ($this->started) {
                $this->items->next();
            } else {
                $this->started = true;
            }

            if (!$this->items->valid()) {
                return null;
            }

            return [$this->items->current(), $this->items->key()];
        });
    }
}
