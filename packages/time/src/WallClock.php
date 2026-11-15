<?php

declare(strict_types=1);

namespace Thesis\Time;

use Psr\Clock\ClockInterface;

/**
 * The current time of the system, in the given time zone or the default one.
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
