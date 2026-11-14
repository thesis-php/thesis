<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

/**
 * The clock of `Thesis\Async\VirtualTime\run()`: seconds since it started.
 * While `run()` runs, it is bound to {@see Scope::$virtualClock}, the clock of the library.
 *
 * @internal
 */
final class VirtualClock
{
    private float $now = 0.0;

    public function now(): float
    {
        return $this->now;
    }

    /**
     * @throws \ValueError if $seconds is negative: the clock is monotonic
     */
    public function advance(float $seconds): void
    {
        if ($seconds < 0) {
            throw new \ValueError('The clock is monotonic: it cannot be moved back.');
        }

        $this->now += $seconds;
    }

    /**
     * Jumps to $at, unless the clock is already past it.
     */
    public function jump(float $at): void
    {
        $this->now = max($this->now, $at);
    }
}
