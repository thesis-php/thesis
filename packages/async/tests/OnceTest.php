<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Test;
use function Thesis\Async\Tests\never;
use function Thesis\Async\Tests\reaches;
use function Thesis\Async\Tests\thrown;
use function Thesis\Time\sec;

#[Test]
final class OnceTest
{
    public function concurrentAndLaterCallersShareOneResult(): void
    {
        $calls = 0;
        $once = new Once(static function () use (&$calls) {
            ++$calls;
            delay(sec(1));

            return new \stdClass();
        });
        Assert::same($calls, 0);

        $results = all([$once->await(...), $once->await(...), $once->await(...)]);
        $value = $once->await();

        Assert::same($results, [$value, $value, $value]);
        Assert::same($calls, 1);
    }

    public function cachesNullAndReleasesCallback(): void
    {
        $calls = 0;
        $fn = static function () use (&$calls) {
            ++$calls;

            return null;
        };
        $once = new Once($fn);

        Assert::null($once->await());
        Assert::null($once->await());
        Assert::same($calls, 1);
        Assert::false(reaches($once, $fn));
    }

    public function ordinaryFailureIsFinalEvenWithRetryEnabled(): void
    {
        $calls = 0;
        $failure = new \RuntimeException();
        $once = new Once(static function () use (&$calls, $failure) {
            ++$calls;
            delay(sec(1));

            throw $failure;
        }, retryOnCancel: true);
        $await = static fn() => thrown($once->await(...));

        Assert::same(all([$await, $await]), [$failure, $failure]);
        Assert::same($await(), $failure);
        Assert::same($calls, 1);
    }

    public function cancellationIsFinalByDefault(): void
    {
        $once = new Once(never(...));

        group(static function (Group $group) use ($once) {
            $waiter = $group->spawn(static fn() => thrown($once->await(...)));
            $timeout = thrown(static fn() => timeout(sec(1), $once->await(...)));

            Assert::instanceOf($timeout, TimedOut::class);
            $failure = $waiter->await();
            Assert::instanceOf($failure, OnceCancelled::class);
            Assert::instanceOf($failure->getPrevious(), Cancelled::class);
            Assert::same($failure->getPrevious(), $timeout->getPrevious());
            Assert::same(thrown($once->await(...)), $failure);
        });
    }

    public function waitingCallersShareOneRetryAfterCancellation(): void
    {
        $calls = 0;
        $once = new Once(static function () use (&$calls) {
            if (++$calls === 1) {
                never();
            }

            delay(sec(1));

            return new \stdClass();
        }, retryOnCancel: true);

        $results = group(static function (Group $group) use ($once) {
            $first = $group->spawn($once->await(...));
            $second = $group->spawn($once->await(...));
            $timeout = thrown(static fn() => timeout(sec(1), $once->await(...)));
            Assert::instanceOf($timeout, TimedOut::class);

            return [$first->await(), $second->await()];
        });

        $value = $once->await();
        Assert::same($results, [$value, $value]);
        Assert::same($calls, 2);
    }

    public function cancellingAWaiterLeavesTheRunningAttemptAlone(): void
    {
        $calls = 0;
        $once = new Once(static function () use (&$calls) {
            ++$calls;
            delay(sec(2));

            return 'ready';
        }, retryOnCancel: true);

        group(static function (Group $group) use ($once) {
            $waiter = $group->spawn(static fn() => thrown(static fn() => timeout(sec(1), $once->await(...))));
            Assert::same($once->await(), 'ready');
            Assert::instanceOf($waiter->await(), TimedOut::class);
        });

        Assert::same($once->await(), 'ready');
        Assert::same($calls, 1);
    }

    public function waiterCancelledAfterWakeLeavesRetryForALaterCaller(): void
    {
        $calls = 0;
        $once = new Once(static function () use (&$calls) {
            if (++$calls === 1) {
                never();
            }

            return 'ready';
        }, retryOnCancel: true);

        group(static function (Group $group) use ($once) {
            $waiter = $group->spawn(static fn() => thrown($once->await(...)));
            $timeout = thrown(static fn() => timeout(sec(1), $once->await(...)));
            Assert::instanceOf($timeout, TimedOut::class);

            // The first attempt has woken the waiter, but the current fiber still owns this tick.
            $waiter->cancel();
            Assert::instanceOf($waiter->await(), Cancelled::class);
        });

        Assert::same($calls, 1);
        Assert::same($once->await(), 'ready');
        Assert::same($calls, 2);
    }

    public function cancellationOfANestedOnceIsAnOrdinaryFailure(): void
    {
        $inner = new Once(never(...));
        $timeout = thrown(static fn() => timeout(sec(1), $inner->await(...)));
        Assert::instanceOf($timeout, TimedOut::class);
        $failure = thrown($inner->await(...));
        Assert::instanceOf($failure, OnceCancelled::class);

        $outer = new Once($inner->await(...), retryOnCancel: true);

        Assert::same(thrown($outer->await(...)), $failure);
        Assert::same(thrown($outer->await(...)), $failure);
    }
}
