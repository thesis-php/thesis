<?php

declare(strict_types=1);

namespace Thesis\Time;

use Psr\Clock\ClockInterface;

/**
 * The clock returns the current system time in the requested time zone or the default time zone.
 *
 * @api
 */
final readonly class WallClock implements ClockInterface
{
    public function __construct(
        private ?\DateTimeZone $timeZone = null,
    ) {}

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(timezone: $this->timeZone);
    }
}
