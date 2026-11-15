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
     * @throws \ValueError if $nanoseconds is negative: the clock is monotonic
     */
    public function advance(int $nanoseconds): void
    {
        if ($nanoseconds < 0) {
            throw new \ValueError('The clock is monotonic: it cannot be moved back.');
        }

        $this->now += $nanoseconds;
    }

    /**
     * Jumps to $at, unless the clock is already past it.
     */
    public function jump(int $at): void
    {
        $this->now = max($this->now, $at);
    }
}
