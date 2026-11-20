<?php

declare(strict_types=1);

namespace Thesis\Time;

use Psr\Clock\ClockInterface;
use Time\Duration;

/**
 * A clock for tests: the time stands still until advance() moves it or $now is assigned.
 *
 * @api
 */
final class FrozenClock implements ClockInterface
{
    public function __construct(
        public \DateTimeImmutable $now = new \DateTimeImmutable(),
    ) {}

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    /**
     * Moves the time by the duration, back if it is negative.
     */
    public function advance(Duration $duration): void
    {
        $this->now = shiftTime($this->now, $duration);
    }
}
