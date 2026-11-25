<?php

declare(strict_types=1);

namespace Thesis\Time;

use Time\Duration;

/**
 * Measures real elapsed time on the system's monotonic clock.
 *
 * @api
 */
final class Stopwatch
{
    public static function start(): self
    {
        return new self(self::now());
    }

    private Duration $lastLap;

    private function __construct(
        private readonly Duration $start,
    ) {
        $this->lastLap = $start;
    }

    /**
     * Time since start(), including all laps.
     */
    public function elapsed(): Duration
    {
        return self::now()->sub($this->start);
    }

    /**
     * Time since the previous lap(), or since start() for the first lap.
     */
    public function lap(): Duration
    {
        $now = self::now();
        $lap = $now->sub($this->lastLap);
        $this->lastLap = $now;

        return $lap;
    }

    private static function now(): Duration
    {
        return Duration::fromSeconds(...hrtime());
    }
}
