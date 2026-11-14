<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\never;

#[Test]
final class LocalRateLimiterTest
{
    public function burstStartsAtOnceThenOneEveryInterval(): void
    {
        $limiter = new LocalRateLimiter(perSecond: 1, burst: 3);
        $start = now();

        $limiter->wait();
        $limiter->wait();
        $limiter->wait();
        $burst = now() - $start;
        $limiter->wait();
        $fourth = now() - $start;
        $limiter->wait();
        $fifth = now() - $start;

        Assert::same([$burst, $fourth, $fifth], [0.0, 1.0, 2.0]);
        assertNoCallbacksLeft();
    }

    public function waitersGetTokensInOrderOfArrival(): void
    {
        $limiter = new LocalRateLimiter(perSecond: 1, burst: 1);
        $limiter->wait();
        $log = [];

        group(static function (Group $group) use ($limiter, &$log) {
            foreach (['a', 'b', 'c'] as $name) {
                $group->spawn(static function () use ($limiter, $name, &$log) {
                    $limiter->wait();
                    $log[] = $name;
                });
            }
        });

        Assert::same($log, ['a', 'b', 'c']);
    }

    public function waitWithTokenAvailableDoesNotSuspend(): void
    {
        $limiter = new LocalRateLimiter(perSecond: 1, burst: 1);

        $thrown = timeout(1, static function () use ($limiter) {
            try {
                never();
            } catch (Cancelled) {
            }

            try {
                $limiter->wait();
            } catch (Cancelled $cancelled) {
                return $cancelled;
            }

            return null;
        });

        Assert::null($thrown);
    }

    public function cancelledSleeperTakesNoToken(): void
    {
        $limiter = new LocalRateLimiter(perSecond: 0.5, burst: 1);
        $limiter->wait();
        $start = now();

        $result = timeoutOr(1, $limiter->wait(...), 'timed out');
        $limiter->wait();

        Assert::same($result, 'timed out');
        Assert::same(now() - $start, 2.0); // 4.0 if the cancelled wait counted
        assertNoCallbacksLeft();
    }

    public function cancelledQueuedWaiterTakesNoToken(): void
    {
        $limiter = new LocalRateLimiter(perSecond: 0.5, burst: 1);
        $limiter->wait();
        $start = now();

        $at = group(static function (Group $group) use ($limiter, $start) {
            $sleeper = $group->spawn(static function () use ($limiter, $start) {
                $limiter->wait();

                return now() - $start;
            });
            $queued = $group->spawn($limiter->wait(...));
            $last = $group->spawn(static function () use ($limiter, $start) {
                $limiter->wait();

                return now() - $start;
            });
            delay(1);

            $queued->cancel();

            return [$sleeper->await(), $last->await()];
        });

        Assert::same($at, [2.0, 4.0]); // 6.0 last if the cancelled wait counted
    }

    public function idleRefillIsCappedAtBurst(): void
    {
        $limiter = new LocalRateLimiter(perSecond: 0.5, burst: 2);
        $limiter->wait();
        $limiter->wait();
        delay(10);
        $start = now();

        $limiter->wait();
        $limiter->wait();
        $afterBurst = now() - $start;
        $limiter->wait();
        $third = now() - $start;

        Assert::same([$afterBurst, $third], [0.0, 2.0]);
    }

    #[DataSet([0.0])]
    #[DataSet([-1.0])]
    #[DataSet([NAN])]
    public function rateMustBePositive(float $perSecond): void
    {
        Expect::exception(\ValueError::class);

        new LocalRateLimiter($perSecond, 1);
    }
}
