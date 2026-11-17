<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

/**
 * A timer of {@see Loop}: due at {@see $expiration} of the clock of the loop, it wakes its waiter once.
 *
 * @internal
 */
final class Timer
{
    /**
     * @param int $expiration in nanoseconds of the clock of the loop
     * @param int $order breaks the ties of the heap of the loop: of two timers due at once, the earlier one fires first
     * @param ?Waiter $waiter null once the timer has fired or been cancelled: its entry in the heap is outdated then,
     *                        and holds no waiter
     */
    public function __construct(
        public readonly int $expiration,
        public readonly int $order,
        public ?Waiter $waiter,
        public readonly bool $referenced,
    ) {}
}
