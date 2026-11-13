<?php

declare(strict_types=1);

namespace Thesis\Async;

use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use function Amp\delay as ampDelay;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\elapsed;
use function Thesis\Async\Tests\thrown;

#[Test]
final class AmpCancellableTest
{
    public function returnsResultWithoutCancellation(): void
    {
        $result = ampCancellable(static function ($cancellation) {
            ampDelay(0.001, cancellation: $cancellation);

            return 'result';
        });

        Assert::same($result, 'result');
        assertNoCallbacksLeft();
    }

    public function amphpDelayIsInterruptedByTimeout(): void
    {
        $exception = null;

        $elapsed = elapsed(static function () use (&$exception) {
            $exception = thrown(static fn() => timeout(0.01, static fn() => ampCancellable(
                static fn($cancellation) => ampDelay(10, cancellation: $cancellation),
            )));
        });

        Assert::instanceOf($exception, TimedOut::class);
        Assert::numeric($elapsed)->lessThan(500);
        assertNoCallbacksLeft();
    }

    public function amphpFutureAwaitIsInterruptedByTimeout(): void
    {
        /** @var DeferredFuture<string> */
        $deferred = new DeferredFuture();

        Expect::exception(TimedOut::class);

        timeout(0.001, static fn() => ampCancellable(
            static fn($cancellation) => $deferred->getFuture()->await($cancellation),
        ));
    }

    public function ownCancellationOfOperationIsRethrownAsIs(): void
    {
        $exception = thrown(static fn() => timeout(1, static fn() => ampCancellable(
            static fn() => ampDelay(10, cancellation: new TimeoutCancellation(0.001)),
        )));

        Assert::instanceOf($exception, CancelledException::class);
        assertNoCallbacksLeft();
    }

    public function throwsCancelledWithoutCallingFnInCancelledScope(): void
    {
        $exception = thrown(static fn() => timeout(0.001, static function () {
            try {
                delay(1);
            } catch (Cancelled) {
            }

            ampCancellable(static function () {
                throw new \LogicException('fn is called');
            });
        }));

        Assert::instanceOf($exception, TimedOut::class);
    }

    public function isNotCancelledInsideShield(): void
    {
        $events = [];

        $exception = thrown(static function () use (&$events) {
            timeout(0.001, static function () use (&$events) {
                try {
                    ampCancellable(static fn($cancellation) => ampDelay(1, cancellation: $cancellation));
                } finally {
                    shield(static function () use (&$events) {
                        ampCancellable(static fn($cancellation) => ampDelay(0.005, cancellation: $cancellation));
                        $events[] = 'done';
                    });
                }
            });
        });

        Assert::instanceOf($exception, TimedOut::class);
        Assert::same($events, ['done']);
    }

    public function taskCancelInterruptsAmphpWait(): void
    {
        $exception = group(static function (Group $group) {
            $task = $group->spawn(static fn() => ampCancellable(
                static fn($cancellation) => ampDelay(10, cancellation: $cancellation),
            ));

            ampDelay(0.001);
            $task->cancel();

            return thrown($task->await(...));
        });

        Assert::instanceOf($exception, TaskCancelled::class);
        assertNoCallbacksLeft();
    }

    public function groupFailureInterruptsAmphpWaitsOfSiblingsAndBody(): void
    {
        $exception = thrown(static fn() => group(static function (Group $group) {
            foreach (range(1, 3) as $_) {
                $group->spawn(static fn() => ampCancellable(
                    static fn($cancellation) => ampDelay(10, cancellation: $cancellation),
                ));
            }

            $group->spawn(static function () {
                ampDelay(0.001);

                throw new \RuntimeException('task failed');
            });

            ampCancellable(static fn($cancellation) => ampDelay(10, cancellation: $cancellation));
        }));

        Assert::instanceOf($exception, \RuntimeException::class);
        Assert::same($exception->getMessage(), 'task failed');
        assertNoCallbacksLeft();
    }

    public function amphpWaitThatEndsTogetherWithCancellationDoesNotBreakJoin(): void
    {
        // The amphp timer and the deadline fire in the same tick: the cancellation callback is queued for
        // a suspension that has already been resumed by the timer. It must not reach the next wait (the join).
        foreach (range(1, 20) as $_) {
            $exception = thrown(static fn() => timeout(0.002, static fn() => group(static function (Group $group) {
                $group->spawn(static fn() => ampCancellable(
                    static fn($cancellation) => ampDelay(0.002, cancellation: $cancellation),
                ));
                $group->spawn(static fn() => ampCancellable(
                    static fn($cancellation) => ampDelay(10, cancellation: $cancellation),
                ));
            })));

            Assert::instanceOf($exception, TimedOut::class);
        }

        assertNoCallbacksLeft();
    }
}
