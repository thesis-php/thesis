<?php

declare(strict_types=1);

namespace Thesis\Time;

use Testo\Assert;
use Testo\Test;

#[Test]
final class StopwatchTest
{
    public function lapsPartitionTheTotalTime(): void
    {
        $watch = Stopwatch::start();
        usleep(1000);
        $before = $watch->elapsed();
        $first = $watch->lap();
        usleep(1000);
        $between = $watch->elapsed();
        $sum = $first->add($watch->lap());

        Assert::true($before >= ms(1));
        Assert::true($before <= $first && $first < $between);
        Assert::true($between <= $sum && $sum <= $watch->elapsed());
    }
}
