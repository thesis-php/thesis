<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Thesis\Async\LocalMutex;
use function Thesis\Async\synchronized;

/**
 * The worker pool behind `Thesis\Async\each()` and `Thesis\Async\map()`: at most $concurrency tasks,
 * each takes the next item once it is done with the previous one.
 *
 * Workers are started lazily: a new one only when the item just taken leaves no other worker free,
 * so a short iterable does not start $concurrency tasks.
 *
 * The iterator is shared, so it is advanced under a mutex: a generator may suspend (fetch the next page),
 * and another worker must not resume it meanwhile. An array cannot suspend, so it is advanced without one.
 *
 * @internal
 * @template K
 * @template T
 */
final class Workers
{
    private bool $started = false;

    private int $workers = 0;

    /**
     * workers that are inside $fn.
     */
    private int $busy = 0;

    /**
     * @template TKey
     * @template TValue
     * @param iterable<TKey, TValue> $items
     * @param positive-int $concurrency
     * @param \Closure(TValue, TKey): mixed $fn
     * @param callable(TValue): mixed $traced the callable of the user that $fn calls: the task of a worker in the trace
     */
    public static function start(
        GroupImpl $group,
        iterable $items,
        int $concurrency,
        \Closure $fn,
        callable $traced,
    ): void {
        new self(
            group: $group,
            items: (static fn() => yield from $items)(),
            mutex: $concurrency === 1 || \is_array($items) ? null : new LocalMutex(),
            concurrency: $concurrency,
            fn: $fn,
            traced: $traced,
        )->spawn();
    }

    /**
     * @param \Generator<K, T> $items
     * @param ?LocalMutex $mutex guards advancing the iterator, or null for one worker or an array
     * @param positive-int $concurrency
     * @param \Closure(T, K): mixed $fn
     * @param callable(T): mixed $traced
     */
    private function __construct(
        private readonly GroupImpl $group,
        private readonly \Generator $items,
        private readonly ?LocalMutex $mutex,
        private readonly int $concurrency,
        private readonly \Closure $fn,
        private readonly mixed $traced,
    ) {}

    private function spawn(): void
    {
        ++$this->workers;
        $this->group->spawnWrapped($this->work(...), $this->traced, name: null);
    }

    private function work(): void
    {
        while (($next = $this->take()) !== null) {
            // Started before $fn, which may wait: the next item is taken while this one is being processed.
            // A worker that turns out to be unneeded finds the items exhausted and returns.
            if (++$this->busy === $this->workers && $this->workers < $this->concurrency) {
                $this->spawn();
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
        if ($this->mutex === null) {
            return $this->pull();
        }

        return synchronized($this->mutex, $this->pull(...));
    }

    /**
     * @return ?array{T, K}
     */
    private function pull(): ?array
    {
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
    }
}
