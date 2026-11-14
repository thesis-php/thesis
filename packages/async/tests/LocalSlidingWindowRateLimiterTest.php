<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\untilCancelled;

#[Test]
final class LocalSlidingWindowRateLimiterTest
{
    public function atMostCallsStartWithinAnyWindow(): void
    {
        $limiter = new LocalSlidingWindowRateLimiter(calls: 3, seconds: 10);
        $start = now();
        /** @var \SplQueue<float> $window the starts of the last 3 calls */
        $window = new \SplQueue();
        $firstWindow = 0.0;
        $shortestWindow = INF;

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

        Assert::same([$firstWindow, $shortestWindow, $total], [0.0, 10.0, 20.0]); // 30.0 or more if a window held fewer calls
        assertNoCallbacksLeft();
    }

    /**
     * Windows that are not exact in binary: a wake-up a hair before the oldest start leaves the window
     * must not make the call wait a delay so small that the clock does not move (it hung on virtual time).
     *
     * @param positive-int $calls
     */
    #[DataSet([1, 0.1])]
    #[DataSet([2, 0.3])]
    #[DataSet([3, 1.5])]
    #[DataSet([10, 0.7])]
    public function callsStartOnScheduleAtAnyWindow(int $calls, float $seconds): void
    {
        $limiter = new LocalSlidingWindowRateLimiter($calls, $seconds);
        $start = now();

        for ($call = 0; $call < $calls * 30; ++$call) {
            $limiter->wait();
            $expected = intdiv($call, $calls) * $seconds;

            Assert::true(abs(now() - $start - $expected) < 1e-9, \sprintf('Call %d started at %s instead of %s.', $call, now() - $start, $expected));
        }

        assertNoCallbacksLeft();
    }

    public function limiterUsedOutsideRunWorksInside(): void
    {
        delay(100); // the clock of the inner run() must not start behind the time the limiter has seen
        $limiter = new LocalSlidingWindowRateLimiter(calls: 1, seconds: 1);
        $limiter->wait();

        $elapsed = VirtualTime\run(static function () use ($limiter) {
            $start = now();
            $limiter->wait();

            return now() - $start;
        });

        Assert::same($elapsed, 1.0);
    }

    public function fullWindowIsAvailableAfterPause(): void
    {
        $limiter = new LocalSlidingWindowRateLimiter(calls: 2, seconds: 20);
        $limiter->wait();
        $limiter->wait();
        delay(40);
        $start = now();

        $limiter->wait();
        $limiter->wait();
        $afterWindow = now() - $start;
        $limiter->wait();
        $third = now() - $start;

        Assert::same([$afterWindow, $third], [0.0, 20.0]);
    }

    public function waitersGetTurnsInOrderOfArrival(): void
    {
        $limiter = new LocalSlidingWindowRateLimiter(calls: 1, seconds: 1);
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
        $limiter = new LocalSlidingWindowRateLimiter(calls: 1, seconds: 1_000);

        $thrown = timeout(1, static function () use ($limiter) {
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

    public function cancelledSleeperCountsNoCall(): void
    {
        $limiter = new LocalSlidingWindowRateLimiter(calls: 1, seconds: 20);
        $limiter->wait();
        $start = now();

        $result = timeoutOr(3, $limiter->wait(...), 'timed out');
        $limiter->wait();

        Assert::same($result, 'timed out');
        Assert::same(now() - $start, 20.0); // 40.0 if the cancelled wait counted
        assertNoCallbacksLeft();
    }

    public function cancelledQueuedWaiterCountsNoCall(): void
    {
        $limiter = new LocalSlidingWindowRateLimiter(calls: 1, seconds: 20);
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

        Assert::same($at, [20.0, 40.0]); // 60.0 last if the cancelled wait counted
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
