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
use function Thesis\Async\Tests\untilCancelled;

#[Test]
final class AmpCancellableTest
{
    public function returnsResultWithoutCancellation(): void
    {
        $result = ampCancellable(static function ($cancellation) {
            ampDelay(1, cancellation: $cancellation);

            return 'result';
        });

        Assert::same($result, 'result');
        assertNoCallbacksLeft();
    }

    public function amphpDelayIsInterruptedByTimeout(): void
    {
        $exception = null;

        $elapsed = elapsed(static function () use (&$exception) {
            $exception = thrown(static fn() => timeout(10, static fn() => ampCancellable(
                static fn($cancellation) => ampDelay(10_000, cancellation: $cancellation),
            )));
        });

        Assert::instanceOf($exception, TimedOut::class);
        Assert::same($elapsed, 10.0);
        assertNoCallbacksLeft();
    }

    public function amphpFutureAwaitIsInterruptedByTimeout(): void
    {
        /** @var DeferredFuture<string> */
        $deferred = new DeferredFuture();

        Expect::exception(TimedOut::class);

        timeout(1, static fn() => ampCancellable(
            static fn($cancellation) => $deferred->getFuture()->await($cancellation),
        ));
    }

    public function ownCancellationOfOperationIsRethrownAsIs(): void
    {
        $exception = thrown(static fn() => timeout(1000, static fn() => ampCancellable(
            static fn() => ampDelay(10_000, cancellation: new TimeoutCancellation(1)),
        )));

        Assert::instanceOf($exception, CancelledException::class);
        assertNoCallbacksLeft();
    }

    public function throwsCancelledWithoutCallingFnInCancelledScope(): void
    {
        $exception = thrown(static fn() => timeout(1, static function () {
            untilCancelled();

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
            timeout(1, static function () use (&$events) {
                try {
                    ampCancellable(static fn($cancellation) => ampDelay(1000, cancellation: $cancellation));
                } finally {
                    shield(static function () use (&$events) {
                        ampCancellable(static fn($cancellation) => ampDelay(5, cancellation: $cancellation));
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
            $handle = $group->spawn(static fn() => ampCancellable(
                static fn($cancellation) => ampDelay(10_000, cancellation: $cancellation),
            ));

            ampDelay(1);
            $handle->cancel();

            return thrown($handle->await(...));
        });

        Assert::instanceOf($exception, TaskCancelled::class);
        assertNoCallbacksLeft();
    }

    public function groupFailureInterruptsAmphpWaitsOfSiblingsAndBody(): void
    {
        $exception = thrown(static fn() => group(static function (Group $group) {
            foreach (range(1, 3) as $_) {
                $group->spawn(static fn() => ampCancellable(
                    static fn($cancellation) => ampDelay(10_000, cancellation: $cancellation),
                ));
            }

            $group->spawn(static function () {
                ampDelay(1);

                throw new \RuntimeException('task failed');
            });

            ampCancellable(static fn($cancellation) => ampDelay(10_000, cancellation: $cancellation));
        }));

        Assert::instanceOf($exception, \RuntimeException::class);
        Assert::same($exception->getMessage(), 'task failed');
        assertNoCallbacksLeft();
    }

    public function amphpWaitThatEndsTogetherWithCancellationDoesNotBreakJoin(): void
    {
        // The amphp timer and the deadline fire in the same tick: the cancellation callback is queued for
        // a suspension that has already been resumed by the timer. It must not reach the next wait (the join).
        // On virtual time they always do, so one round is enough.
        $exception = thrown(static fn() => timeout(2, static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => ampCancellable(
                static fn($cancellation) => ampDelay(2, cancellation: $cancellation),
            ));
            $group->spawn(static fn() => ampCancellable(
                static fn($cancellation) => ampDelay(10_000, cancellation: $cancellation),
            ));
        })));

        Assert::instanceOf($exception, TimedOut::class);
        assertNoCallbacksLeft();
    }
}
