<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Test;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\secondsSince;
use function Thesis\Async\Tests\thrown;
use function Thesis\Async\Tests\untilCancelled;
use function Thesis\Async\Tests\withoutWarnings;
use function Thesis\Time\ms;
use function Thesis\Time\ns;
use function Thesis\Time\sec;

#[Test]
final class LocalTokenBucketRateLimiterTest
{
    public function burstStartsAtOnceThenOneEveryInterval(): void
    {
        $limiter = new LocalTokenBucketRateLimiter(perSecond: 1, burst: 3);
        $start = Instant::now();

        $limiter->wait();
        $limiter->wait();
        $limiter->wait();
        $burst = secondsSince($start);
        $limiter->wait();
        $fourth = secondsSince($start);
        $limiter->wait();
        $fifth = secondsSince($start);

        Assert::same([$burst, $fourth, $fifth], [0.0, 1.0, 2.0]);
        assertNoCallbacksLeft();
    }

    /**
     * Rates that are not a power of two: the time of a token is not exact in binary, and a wake-up a hair early
     * must not make the next token wait a delay so small that the clock does not move (it hung on virtual time).
     *
     * @param positive-int $burst
     */
    #[DataSet([10.0, 1])]
    #[DataSet([10.0, 10])]
    #[DataSet([3.0, 2])]
    #[DataSet([0.3, 1])]
    #[DataSet([100.0, 5])]
    #[DataSet([1.5, 3])]
    public function callsStartOnScheduleAtAnyRate(float $perSecond, int $burst): void
    {
        $limiter = new LocalTokenBucketRateLimiter($perSecond, $burst);
        $start = Instant::now();

        for ($call = 0; $call < $burst + 30; ++$call) {
            $limiter->wait();
            $expected = max(0, $call - $burst + 1) / $perSecond;

            Assert::true(
                actual: abs(secondsSince($start) - $expected) < 1e-9,
                message: \sprintf('Call %d started at %s instead of %s.', $call, secondsSince($start), $expected),
            );
        }

        assertNoCallbacksLeft();
    }

    public function limiterCreatedOutsideRunWorksInside(): void
    {
        delay(sec(100)); // the clock of the inner virtualTime() must not start behind the time the limiter has seen
        $limiter = new LocalTokenBucketRateLimiter(perSecond: 1, burst: 1);
        $limiter->wait();

        $elapsed = virtualTime(static function () use ($limiter) {
            $start = Instant::now();
            $limiter->wait();

            return secondsSince($start);
        });

        Assert::same($elapsed, 1.0);
    }

    public function waitersGetTokensInOrderOfArrival(): void
    {
        $limiter = new LocalTokenBucketRateLimiter(perSecond: 1, burst: 1);
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
        $limiter = new LocalTokenBucketRateLimiter(perSecond: 1, burst: 1);

        $thrown = timeout(sec(1), static function () use ($limiter) {
            untilCancelled();

            try {
                $limiter->wait();
            } catch (Cancelled $cancelled) {
                return $cancelled;
            }

            return null;
        });

        Assert::null($thrown);
    }

    public function tokensComeAtIntervalRoundedToNanoseconds(): void
    {
        $limiter = new LocalTokenBucketRateLimiter(perSecond: 3, burst: 1);
        $limiter->wait();
        $start = Instant::now();

        $limiter->wait();
        $second = Instant::now()->since($start);
        $limiter->wait();

        Assert::equals([$second, Instant::now()->since($start)], [ns(333_333_333), ns(666_666_667)]);
    }

    public function bucketFullExactlyAtCallStartsCountingAnew(): void
    {
        $limiter = new LocalTokenBucketRateLimiter(perSecond: 3, burst: 1);
        $limiter->wait();
        $start = Instant::now();
        delay(ns(333_333_333));

        $limiter->wait();
        $limiter->wait();

        // Counted on from the first token, it would be 666 666 667: the second interval rounded up.
        Assert::equals(Instant::now()->since($start), ns(666_666_666));
    }

    public function cancelledSleeperTakesNoToken(): void
    {
        $limiter = new LocalTokenBucketRateLimiter(perSecond: 0.5, burst: 1);
        $limiter->wait();
        $start = Instant::now();

        $result = timeoutOr(sec(1), $limiter->wait(...), default: 'timed out');
        $limiter->wait();

        Assert::same($result, 'timed out');
        Assert::same(secondsSince($start), 2.0); // 4.0 if the cancelled wait counted
        assertNoCallbacksLeft();
    }

    public function cancelledQueuedWaiterTakesNoToken(): void
    {
        $limiter = new LocalTokenBucketRateLimiter(perSecond: 0.5, burst: 1);
        $limiter->wait();
        $start = Instant::now();

        $at = group(static function (Group $group) use ($limiter, $start) {
            $sleeper = $group->spawn(static function () use ($limiter, $start) {
                $limiter->wait();

                return secondsSince($start);
            });
            $queued = $group->spawn($limiter->wait(...));
            $last = $group->spawn(static function () use ($limiter, $start) {
                $limiter->wait();

                return secondsSince($start);
            });
            delay(sec(1));

            $queued->cancel();

            return [$sleeper->await(), $last->await()];
        });

        Assert::same($at, [2.0, 4.0]); // 6.0 last if the cancelled wait counted
    }

    public function idleRefillIsCappedAtBurst(): void
    {
        $limiter = new LocalTokenBucketRateLimiter(perSecond: 0.5, burst: 2);
        $limiter->wait();
        $limiter->wait();
        delay(sec(10));
        $start = Instant::now();

        $limiter->wait();
        $limiter->wait();
        $afterBurst = secondsSince($start);
        $limiter->wait();
        $third = secondsSince($start);

        Assert::same([$afterBurst, $third], [0.0, 2.0]);
    }

    public function lateWakeUpTakesTokenFromRefilledBucket(): void
    {
        $limiter = new LocalTokenBucketRateLimiter(perSecond: 1, burst: 1);
        $limiter->wait();
        $start = Instant::now();

        $starts = group(static function (Group $group) use ($limiter, $start) {
            // Blocks the loop while the body sleeps for the next token.
            $group->spawn(static function () {
                delay(sec(0));
                burnCpu(sec(10));
            });

            $limiter->wait();
            $late = secondsSince($start);
            $limiter->wait();

            return [$late, secondsSince($start)];
        });

        Assert::same($starts, [10.0, 11.0]);
    }

    public function wakeUpLateByOneIntervalAndAHalfTakesTokenFromRefilledBucket(): void
    {
        $limiter = new LocalTokenBucketRateLimiter(perSecond: 1, burst: 1);
        $limiter->wait();
        $start = Instant::now();

        $starts = group(static function (Group $group) use ($limiter, $start) {
            $group->spawn(static function () {
                delay(sec(0));
                burnCpu(ms(2500));
            });

            $limiter->wait();
            $late = secondsSince($start);
            $limiter->wait();

            return [$late, secondsSince($start)];
        });

        Assert::same($starts, [2.5, 3.5]);
    }

    public function wakeUpLateByExactlyOneIntervalTakesTokenFromRefilledBucket(): void
    {
        $limiter = new LocalTokenBucketRateLimiter(perSecond: 3, burst: 1);
        $limiter->wait();
        $start = Instant::now();

        $starts = group(static function (Group $group) use ($limiter, $start) {
            // The second token comes at 666 666 667 ns.
            $group->spawn(static function () {
                delay(sec(0));
                burnCpu(ns(666_666_667));
            });

            $limiter->wait();
            $late = Instant::now()->since($start);
            $limiter->wait();

            return [$late, Instant::now()->since($start)];
        });

        Assert::equals($starts, [ns(666_666_667), ns(1_000_000_000)]);
    }

    /**
     * A real timer wakes up a little late on every call: unless it is late by a whole token interval,
     * the next token comes on schedule, or the limiter would run below its rate.
     */
    public function wakeUpLateByLessThanIntervalKeepsSchedule(): void
    {
        $limiter = new LocalTokenBucketRateLimiter(perSecond: 0.5, burst: 1);
        $limiter->wait();
        $start = Instant::now();

        $starts = group(static function (Group $group) use ($limiter, $start) {
            // Blocks the loop while the body sleeps for the next token, due in 2 seconds.
            $group->spawn(static function () {
                delay(sec(0));
                burnCpu(sec(3));
            });

            $limiter->wait();
            $late = secondsSince($start);
            $limiter->wait();

            return [$late, secondsSince($start)];
        });

        Assert::same($starts, [3.0, 4.0]);
    }

    /**
     * A token 2^63 nanoseconds away is just beyond int: as a float, PHP_INT_MAX is 2^63.
     */
    #[DataSet([1e-10])]
    #[DataSet([1e9 / 2.0 ** 63])]
    public function tokenTooFarAwayDoesNotOverflowClock(float $perSecond): void
    {
        $limiter = new LocalTokenBucketRateLimiter(perSecond: $perSecond, burst: 1);
        $limiter->wait();

        $result = withoutWarnings(static fn() => timeoutOr(
            duration: sec(1000),
            body: static function () use ($limiter) {
                $limiter->wait();

                return 'started';
            },
            default: 'expired',
        ));

        Assert::same($result, 'expired');
    }

    #[DataSet([0.0, '0'])]
    #[DataSet([-1.0, '-1'])]
    #[DataSet([-INF, '-INF'])]
    #[DataSet([NAN, 'NAN'])]
    public function rateMustBePositive(float $perSecond, string $shown): void
    {
        $exception = withoutWarnings(
            fn: static fn() => thrown(static fn() => new LocalTokenBucketRateLimiter($perSecond, 1)),
        );

        Assert::instanceOf($exception, \ValueError::class);
        Assert::same(
            actual: $exception->getMessage(),
            expected: \sprintf('LocalTokenBucketRateLimiter needs a positive rate, got %s.', $shown),
        );
    }
}
