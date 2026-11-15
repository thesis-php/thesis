<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\elapsed;
use function Thesis\Async\Tests\thrown;
use function Thesis\Async\Tests\untilCancelled;
use function Thesis\Async\VirtualTime\advance;
use function Thesis\Time\sec;

#[Test]
final class DelayAndCheckpointTest
{
    public function delayWaitsGivenTime(): void
    {
        Assert::same(elapsed(static fn() => delay(sec(20))), 20.0);
        assertNoCallbacksLeft();
    }

    public function delayIsCancellable(): void
    {
        $exception = null;

        $elapsed = elapsed(static function () use (&$exception) {
            $exception = thrown(static fn() => timeout(sec(1), static fn() => delay(sec(10_000))));
        });

        Assert::instanceOf($exception, TimedOut::class);
        Assert::same($elapsed, 1.0);
        assertNoCallbacksLeft();
    }

    public function delayOfZeroOrLessWaitsAsZero(): void
    {
        foreach ([0, -1] as $seconds) {
            $order = [];

            group(static function (Group $group) use ($seconds, &$order) {
                $group->spawn(static function () use (&$order) {
                    $order[] = 'task';
                });

                $order[] = 'before';
                Assert::same(elapsed(static fn() => delay(sec($seconds))), 0.0);
                $order[] = 'after';
            });

            Assert::same($order, ['before', 'task', 'after']);
            assertNoCallbacksLeft();
        }
    }

    public function delayOfZeroOrLessIsCancellationPoint(): void
    {
        $exception = thrown(static fn() => timeout(sec(1), static function () {
            untilCancelled();

            delay(sec(-1));
        }));

        Assert::instanceOf($exception, TimedOut::class);
    }

    public function delayLetsOtherTasksRun(): void
    {
        $events = [];

        group(static function (Group $group) use (&$events) {
            $group->spawn(static function () use (&$events) {
                delay(sec(20));
                $events[] = 'slow';
            });
            $group->spawn(static function () use (&$events) {
                delay(sec(1));
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

        timeout(sec(1), static function () {
            untilCancelled();

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
            timeout(sec(5), static function () use (&$iterations) {
                while ($iterations < 1000) {
                    ++$iterations;
                    advance(sec(1));
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
