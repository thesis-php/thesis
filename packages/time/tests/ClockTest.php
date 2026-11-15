<?php

declare(strict_types=1);

namespace Thesis\Time;

use Testo\Assert;
use Testo\Test;

#[Test]
final class ClockTest
{
    public function wallClockTellsTheCurrentTime(): void
    {
        $before = new \DateTimeImmutable();
        $now = new WallClock()->now();

        Assert::true($before <= $now && $now <= new \DateTimeImmutable());
        Assert::same($now->getTimezone()->getName(), date_default_timezone_get());
    }

    public function wallClockUsesTheTimeZone(): void
    {
        $timeZone = new \DateTimeZone('Arctic/Longyearbyen');

        Assert::same(new WallClock($timeZone)->now()->getTimezone()->getName(), 'Arctic/Longyearbyen');
    }

    public function frozenClockStandsStill(): void
    {
        $time = new \DateTimeImmutable('2026-01-01 00:00:00 UTC');
        $clock = new FrozenClock($time);

        Assert::same($clock->now(), $time);
        Assert::same($clock->now(), $time);
    }

    public function frozenClockAdvances(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01 00:00:00 UTC'));

        $clock->advance(sec(90));
        Assert::same($clock->now()->format('H:i:s'), '00:01:30');

        $clock->advance(minutes(-2));
        Assert::same($clock->now()->format('Y-m-d H:i:s'), '2025-12-31 23:59:30');
    }

    public function frozenClockIsSet(): void
    {
        $clock = new FrozenClock();
        $time = new \DateTimeImmutable('2000-01-01 00:00:00 UTC');

        $clock->now = $time;

        Assert::same($clock->now(), $time);
    }
}
