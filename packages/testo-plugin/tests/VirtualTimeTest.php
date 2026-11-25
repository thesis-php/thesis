<?php

declare(strict_types=1);

namespace Thesis\TestoPlugin;

use Testo\Assert;
use Testo\Test;
use Thesis\Async\Instant;
use Thesis\Time\Stopwatch;
use function Thesis\Async\burnCpu;
use function Thesis\Async\delay;
use function Thesis\Time\hours;
use function Thesis\Time\ms;
use function Thesis\Time\sec;

/**
 * The suite has no VirtualTimePlugin: a test runs on virtual time only by the attribute.
 */
#[Test]
final class VirtualTimeTest
{
    #[VirtualTime]
    public function attributeRunsTestOnVirtualTime(): void
    {
        $start = Instant::now();
        $watch = Stopwatch::start();

        delay(hours(1));
        burnCpu(sec(1));

        Assert::equals(Instant::now()->since($start), sec(3601));
        Assert::true($watch->elapsed() < sec(1));
    }

    public function withoutAttributeTestRunsOnRealTime(): void
    {
        $watch = Stopwatch::start();

        burnCpu(ms(10));

        Assert::true($watch->elapsed() >= ms(10));
    }
}
