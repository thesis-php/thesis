<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;
use function Thesis\Async\Tests\assertNoCallbacksLeft;

#[Test]
final class LocalSlidingWindowRateLimiterTest
{
    public function atMostCallsStartWithinAnyWindow(): void
    {
        $limiter = new LocalSlidingWindowRateLimiter(calls: 3, seconds: 0.01);
        $start = now();
        /** @var \SplQueue<float> $window the starts of the last 3 calls */
        $window = new \SplQueue();
        $firstWindow = 0.0;
        $shortestWindow = INF;

        // Asserted after the loop: the first assertion loads classes, and on a slow file system that takes longer than a window.
        for ($i = 0; $i < 9; ++$i) {
            $limiter->wait();
            $at = now();

            if (\count($window) === 3) {
                $shortestWindow = min($shortestWindow, $at - $window->dequeue());
            } else {
                $firstWindow = $at - $start;
            }

            $window->enqueue($at);
        }

        $total = now() - $start;

        Assert::float($firstWindow)->lessThan(0.005);
        Assert::float($shortestWindow)->greaterThan(0.01);
        Assert::float($total)->lessThan(0.06); // 0.08 or more if a window held fewer calls
        assertNoCallbacksLeft();
    }

    public function fullWindowIsAvailableAfterPause(): void
    {
        $limiter = new LocalSlidingWindowRateLimiter(calls: 2, seconds: 0.02);
        $limiter->wait();
        $limiter->wait();
        delay(0.04);
        $start = now();

        $limiter->wait();
        $limiter->wait();
        $afterWindow = now() - $start;
        $limiter->wait();
        $third = now() - $start;

        Assert::float($afterWindow)->lessThan(0.01);
        Assert::float($third)->greaterThan(0.019);
    }

    public function waitersGetTurnsInOrderOfArrival(): void
    {
        $limiter = new LocalSlidingWindowRateLimiter(calls: 1, seconds: 0.001);
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

    public function waitWithRoomInWindowDoesNotSuspend(): void
    {
        $limiter = new LocalSlidingWindowRateLimiter(calls: 1, seconds: 1);

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

    public function cancelledSleeperCountsNoCall(): void
    {
        $limiter = new LocalSlidingWindowRateLimiter(calls: 1, seconds: 0.02);
        $limiter->wait();
        $start = now();

        $result = timeoutOr(0.003, $limiter->wait(...), 'timed out');
        $limiter->wait();

        Assert::same($result, 'timed out');
        Assert::float(now() - $start)->greaterThan(0.019)->lessThan(0.035); // 0.04 if the cancelled wait counted
        assertNoCallbacksLeft();
    }

    public function cancelledQueuedWaiterCountsNoCall(): void
    {
        $limiter = new LocalSlidingWindowRateLimiter(calls: 1, seconds: 0.02);
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

    #[DataSet([0.0])]
    #[DataSet([-1.0])]
    #[DataSet([NAN])]
    public function windowMustBePositive(float $seconds): void
    {
        Expect::exception(\ValueError::class);

        new LocalSlidingWindowRateLimiter(1, $seconds);
    }
}
