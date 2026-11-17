<?php

declare(strict_types=1);

namespace Thesis\TestoPlugin;

use Testo\Assert;
use Testo\Test;
use Thesis\Async\Instant;
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
        $realStart = hrtime(true);

        delay(hours(1));
        burnCpu(sec(1));

        Assert::equals(Instant::now()->since($start), sec(3601));
        Assert::int(hrtime(true) - $realStart)->lessThan(1_000_000_000);
    }

    public function withoutAttributeTestRunsOnRealTime(): void
    {
        $realStart = hrtime(true);

        burnCpu(ms(10));

        Assert::int(hrtime(true) - $realStart)->greaterThanOrEqual(10 * 1_000_000);
    }
}
