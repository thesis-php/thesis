<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\elapsed;
use function Thesis\Async\Tests\never;
use function Thesis\Async\Tests\thrown;
use function Thesis\Async\VirtualTime\advance;

#[Test]
final class DelayAndCheckpointTest
{
    public function delayWaitsGivenTime(): void
    {
        Assert::same(elapsed(static fn() => delay(20)), 20.0);
        assertNoCallbacksLeft();
    }

    public function delayIsCancellable(): void
    {
        Assert::same(elapsed(static fn() => timeout(1, static fn() => delay(10_000))), 1.0);
        assertNoCallbacksLeft();
    }

    public function delayLetsOtherTasksRun(): void
    {
        $events = [];

        group(static function (Group $group) use (&$events) {
            $group->spawn(static function () use (&$events) {
                delay(20);
                $events[] = 'slow';
            });
            $group->spawn(static function () use (&$events) {
                delay(1);
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

        timeout(1, static function () {
            try {
                never();
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
            timeout(5, static function () use (&$iterations) {
                while ($iterations < 1_000) {
                    ++$iterations;
                    advance(1);
                    checkpoint();
                }
            });
        });

        Assert::instanceOf($exception, TimedOut::class);
        // The deadline passes in the fifth iteration, but its checkpoint is resumed before the timer fires.
        Assert::same($iterations, 6);
        assertNoCallbacksLeft();
    }
}
