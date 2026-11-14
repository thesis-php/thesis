<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

/**
 * The clock of `Thesis\Async\VirtualTime\run()`, in seconds on the same scale as the clock it replaces.
 * While `run()` runs, it is bound to {@see Scope::$virtualClock}, the clock of the library.
 *
 * @internal
 */
final class VirtualClock
{
    public function __construct(
        private float $now,
    ) {}

    public function now(): float
    {
        return $this->now;
    }

    /**
     * @throws \ValueError if $seconds is negative: the clock is monotonic, or NAN
     */
    public function advance(float $seconds): void
    {
        if (is_nan($seconds)) {
            throw new \ValueError('advance() needs a number of seconds, got NAN.');
        }

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
