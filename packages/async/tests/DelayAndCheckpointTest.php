<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\busy;
use function Thesis\Async\Tests\elapsed;
use function Thesis\Async\Tests\thrown;

#[Test]
final class DelayAndCheckpointTest
{
    public function delayWaitsAtLeastGivenTime(): void
    {
        $elapsed = elapsed(static fn() => delay(0.02));

        Assert::numeric($elapsed)->greaterThanOrEqual(19);
        assertNoCallbacksLeft();
    }

    public function delayIsCancellable(): void
    {
        $elapsed = elapsed(static fn() => timeout(0.001, static fn() => delay(10)));

        Assert::numeric($elapsed)->lessThan(1_000);
        assertNoCallbacksLeft();
    }

    public function delayLetsOtherTasksRun(): void
    {
        $events = [];

        group(static function (Group $group) use (&$events) {
            $group->spawn(static function () use (&$events) {
                delay(0.02);
                $events[] = 'slow';
            });
            $group->spawn(static function () use (&$events) {
                delay(0.001);
                $events[] = 'fast';
            });
        });

        Assert::same($events, ['fast', 'slow']);
    }

    public function checkpointReturnsInHealthyScope(): void
    {
        checkpoint();

        assertNoCallbacksLeft();
    }

    public function checkpointThrowsInCancelledScope(): void
    {
        Expect::exception(TimedOut::class);

        timeout(0.001, static function () {
            try {
                delay(1);
            } catch (Cancelled) {
            }

            checkpoint();
        });
    }

    public function checkpointLetsOtherTasksRun(): void
    {
        $ran = group(static function (Group $group) {
            $ran = false;
            $group->spawn(static function () use (&$ran) {
                $ran = true;
            });

            checkpoint();

            return $ran;
        });

        Assert::true($ran);
    }

    public function checkpointLetsDeadlineInterruptCpuBoundLoop(): void
    {
        $iterations = 0;

        $exception = thrown(static function () use (&$iterations) {
            timeout(0.005, static function () use (&$iterations) {
                while ($iterations < 1_000) {
                    ++$iterations;
                    busy(0.001);
                    checkpoint();
                }
            });
        });

        Assert::instanceOf($exception, TimedOut::class);
        Assert::int($iterations)->lessThan(100);
        assertNoCallbacksLeft();
    }
}
