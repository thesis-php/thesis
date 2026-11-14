<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;
use function Thesis\Async\Tests\assertNoCallbacksLeft;

#[Test]
final class LocalRateLimiterTest
{
    public function burstStartsAtOnceThenOneEveryInterval(): void
    {
        $limiter = new LocalRateLimiter(perSecond: 100, burst: 3);
        $start = now();

        $limiter->wait();
        $limiter->wait();
        $limiter->wait();
        $burst = now() - $start;
        $limiter->wait();
        $fourth = now() - $start;
        $limiter->wait();
        $fifth = now() - $start;

        // From the start, not from the previous call: a late wake-up leaves the rest of its token to the next one.
        Assert::float($burst)->lessThan(0.005);
        Assert::float($fourth)->greaterThan(0.009)->lessThan(0.03);
        Assert::float($fifth)->greaterThan(0.019)->lessThan(0.04);
        assertNoCallbacksLeft();
    }

    public function waitersGetTokensInOrderOfArrival(): void
    {
        $limiter = new LocalRateLimiter(perSecond: 1_000, burst: 1);
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

        $thrown = timeout(0.001, static function () use ($limiter) {
            try {
                delay(1);
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
        $limiter = new LocalRateLimiter(perSecond: 50, burst: 1);
        $limiter->wait();
        $start = now();

        $result = timeoutOr(0.003, $limiter->wait(...), 'timed out');
        $limiter->wait();

        Assert::same($result, 'timed out');
        Assert::float(now() - $start)->greaterThan(0.019)->lessThan(0.035); // 0.04 if the cancelled wait counted
        assertNoCallbacksLeft();
    }

    public function cancelledQueuedWaiterTakesNoToken(): void
    {
        $limiter = new LocalRateLimiter(perSecond: 50, burst: 1);
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
            delay(0.001);

            $queued->cancel();

            return [$sleeper->await(), $last->await()];
        });

        Assert::float($at[0])->greaterThan(0.019);
        Assert::float($at[1])->greaterThan(0.039)->lessThan(0.055); // 0.06 if the cancelled wait counted
    }

    public function idleRefillIsCappedAtBurst(): void
    {
        $limiter = new LocalRateLimiter(perSecond: 50, burst: 2);
        $limiter->wait();
        $limiter->wait();
        delay(0.1);
        $start = now();

        $limiter->wait();
        $limiter->wait();
        $afterBurst = now() - $start;
        $limiter->wait();
        $third = now() - $start;

        Assert::float($afterBurst)->lessThan(0.01);
        Assert::float($third)->greaterThan(0.019);
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
