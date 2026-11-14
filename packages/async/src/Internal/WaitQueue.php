<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

/**
 * FIFO of waiters that a waiter may leave from any place: shift() and remove() are O(1), amortized.
 *
 * Draining a plain array with `foreach { unset; break }` from the head is O(n) per call: the iteration starts over
 * the holes left by the previous removals, so n waiters cost O(n^2). Here every waiter has a sequence number,
 * and the head only moves forward, past each removed number once.
 *
 * @internal
 * @template T of object|array<mixed>
 */
final class WaitQueue
{
    /** @var array<int, T> keyed by the sequence number; the hot paths compare it with [] instead of calling a method */
    public private(set) array $items = [];

    private int $head = 0;

    private int $tail = 0;

    /**
     * @param T $item
     * @return int the key for {@see remove()}
     */
    public function push(object|array $item): int
    {
        $key = $this->tail++;
        $this->items[$key] = $item;

        return $key;
    }

    public function remove(int $key): void
    {
        unset($this->items[$key]);
    }

    /**
     * @return ?T the first item, removed from the queue, or null if there is none
     */
    public function shift(): null|object|array
    {
        if ($this->items === []) {
            $this->head = $this->tail;

            return null;
        }

        do {
            $key = $this->head++;
            $item = $this->items[$key] ?? null;
        } while ($item === null);

        unset($this->items[$key]);

        return $item;
    }

    /**
     * @return list<T> every item in the order of arrival; the queue is empty afterwards
     */
    public function drain(): array
    {
        $items = array_values($this->items);
        $this->items = [];
        $this->head = $this->tail;

        return $items;
    }
}
