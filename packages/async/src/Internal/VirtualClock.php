<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

/**
 * The clock of `Thesis\Async\VirtualTime\run()`, in nanoseconds on the same scale as the clock it replaces.
 * While `run()` runs, it is bound to {@see Scope::$virtualClock}, the clock of the library.
 *
 * @internal
 */
final class VirtualClock
{
    public function __construct(
        public private(set) int $now,
    ) {}

    /**
     * Saturates at PHP_INT_MAX, like the deadlines and the timers: an advance of centuries is the end of time.
     *
     * @throws \ValueError if $nanoseconds is negative: the clock is monotonic
     */
    public function advance(int $nanoseconds): void
    {
        if ($nanoseconds < 0) {
            throw new \ValueError('The clock is monotonic: it cannot be moved back.');
        }

        $now = $this->now + $nanoseconds;
        // @phpstan-ignore function.alreadyNarrowedType (an int overflow turns the sum into a float)
        $this->now = \is_int($now) ? $now : PHP_INT_MAX;
    }

    /**
     * Jumps to $at, unless the clock is already past it.
     */
    public function jump(int $at): void
    {
        $this->now = max($this->now, $at);
    }
}
