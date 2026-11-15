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
use function Thesis\Time\sec;
use function Thesis\Time\toSecFloat;

#[Test]
final class LocalSlidingWindowRateLimiterTest
{
    public function atMostCallsStartWithinAnyWindow(): void
    {
        $limiter = new LocalSlidingWindowRateLimiter(limit: 3, window: sec(10));
        $start = Instant::now();
        /** @var \SplQueue<Instant> $window the starts of the last 3 calls */
        $window = new \SplQueue();
        $firstWindow = 0.0;
        $shortestWindow = INF;

        for ($i = 0; $i < 9; ++$i) {
            $limiter->wait();
            $at = Instant::now();

            if (\count($window) === 3) {
                $shortestWindow = min($shortestWindow, toSecFloat($at->since($window->dequeue())));
            } else {
                $firstWindow = secondsSince($start);
            }

            $window->enqueue($at);
        }

        $total = secondsSince($start);

        // The total is 30.0 or more if a window held fewer calls.
        Assert::same([$firstWindow, $shortestWindow, $total], [0.0, 10.0, 20.0]);
        assertNoCallbacksLeft();
    }

    /**
     * Windows that are not exact in binary: a wake-up a hair before the oldest start leaves the window
     * must not make the call wait a delay so small that the clock does not move (it hung on virtual time).
     *
     * @param positive-int $limit
     */
    #[DataSet([1, 0.1])]
    #[DataSet([2, 0.3])]
    #[DataSet([3, 1.5])]
    #[DataSet([10, 0.7])]
    public function callsStartOnScheduleAtAnyWindow(int $limit, float $window): void
    {
        $limiter = new LocalSlidingWindowRateLimiter($limit, sec($window));
        $start = Instant::now();

        for ($call = 0; $call < $limit * 30; ++$call) {
            $limiter->wait();
            $expected = intdiv($call, $limit) * $window;

            Assert::true(
                actual: abs(secondsSince($start) - $expected) < 1e-9,
                message: \sprintf('Call %d started at %s instead of %s.', $call, secondsSince($start), $expected),
            );
        }

        assertNoCallbacksLeft();
    }

    public function limiterUsedOutsideRunWorksInside(): void
    {
        delay(sec(100)); // the clock of the inner run() must not start behind the time the limiter has seen
        $limiter = new LocalSlidingWindowRateLimiter(limit: 1, window: sec(1));
        $limiter->wait();

        $elapsed = VirtualTime\run(static function () use ($limiter) {
            $start = Instant::now();
            $limiter->wait();

            return secondsSince($start);
        });

        Assert::same($elapsed, 1.0);
    }

    public function fullWindowIsAvailableAfterPause(): void
    {
        $limiter = new LocalSlidingWindowRateLimiter(limit: 2, window: sec(20));
        $limiter->wait();
        $limiter->wait();
        delay(sec(40));
        $start = Instant::now();

        $limiter->wait();
        $limiter->wait();
        $afterWindow = secondsSince($start);
        $limiter->wait();
        $third = secondsSince($start);

        Assert::same([$afterWindow, $third], [0.0, 20.0]);
    }

    public function waitersGetTurnsInOrderOfArrival(): void
    {
        $limiter = new LocalSlidingWindowRateLimiter(limit: 1, window: sec(1));
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
        $limiter = new LocalSlidingWindowRateLimiter(limit: 1, window: sec(1000));

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

    public function cancelledSleeperCountsNoCall(): void
    {
        $limiter = new LocalSlidingWindowRateLimiter(limit: 1, window: sec(20));
        $limiter->wait();
        $start = Instant::now();

        $result = timeoutOr(sec(3), $limiter->wait(...), default: 'timed out');
        $limiter->wait();

        Assert::same($result, 'timed out');
        Assert::same(secondsSince($start), 20.0); // 40.0 if the cancelled wait counted
        assertNoCallbacksLeft();
    }

    public function cancelledQueuedWaiterCountsNoCall(): void
    {
        $limiter = new LocalSlidingWindowRateLimiter(limit: 1, window: sec(20));
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

        Assert::same($at, [20.0, 40.0]); // 60.0 last if the cancelled wait counted
    }

    #[DataSet([0, '0s'])]
    #[DataSet([-1, '-1s'])]
    public function windowMustBePositive(int $seconds, string $shown): void
    {
        $exception = thrown(static fn() => new LocalSlidingWindowRateLimiter(1, sec($seconds)));

        Assert::instanceOf($exception, \ValueError::class);
        Assert::same(
            actual: $exception->getMessage(),
            expected: \sprintf('LocalSlidingWindowRateLimiter needs a positive window, got %s.', $shown),
        );
    }
}
