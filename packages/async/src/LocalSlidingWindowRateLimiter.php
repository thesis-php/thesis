<?php

declare(strict_types=1);

namespace Thesis\Async;

use Thesis\Async\Internal\Scope;
use Time\Duration;
use function Thesis\Time\format;
use function Thesis\Time\ns;
use function Thesis\Time\sec;
use function Thesis\Time\toNs;

/**
 * In-process {@see RateLimiter} with a sliding window: at most $limit calls start within any $window,
 * like .NET `SlidingWindowRateLimiter` with its `PermitLimit` and `Window`.
 * For a server that enforces exactly that, where a {@see LocalTokenBucketRateLimiter} would have to be set to half the
 * rate: a token bucket bounds the average, a window bounds every interval.
 *
 * It keeps the start times of the last $limit calls; a call waits until the oldest of them leaves the window.
 * So after a pause $limit calls start at once, and the next ones follow the pattern of the first window.
 * Memory is one int per call of the limit.
 *
 * Fair and cancellable in the same way as {@see LocalTokenBucketRateLimiter}: callers queue on a {@see LocalMutex},
 * only the first in the queue sleeps, and a caller cancelled while queued or sleeping counts no call.
 * {@see wait()} returns without suspending when the window has room and nobody is queued.
 *
 * @api
 */
final class LocalSlidingWindowRateLimiter implements RateLimiter
{
    private readonly LocalMutex $mutex;

    /**
     * @var array<non-negative-int, int> start times of the last calls, a ring; a slot is missing until its first call
     */
    private array $starts = [];

    /**
     * @var non-negative-int the oldest start in the ring
     */
    private int $oldest = 0;

    /**
     * @var int the window in nanoseconds
     */
    private readonly int $window;

    /**
     * @param positive-int $limit how many calls may start within the window
     * @param Duration $window the length of the window, positive
     * @throws \ValueError if $window is not positive
     */
    public function __construct(
        private readonly int $limit,
        Duration $window,
    ) {
        if ($window <= sec(0)) {
            throw new \ValueError(\sprintf(
                'LocalSlidingWindowRateLimiter needs a positive window, got %s.',
                format($window),
            ));
        }

        $this->window = toNs($window);
        $this->mutex = new LocalMutex();
    }

    public function wait(): void
    {
        synchronized($this->mutex, $this->start(...));
    }

    private function start(): void
    {
        $oldestStart = $this->starts[$this->oldest] ?? null;
        $leavesAt = $oldestStart === null ? PHP_INT_MIN : $oldestStart + $this->window;
        // @phpstan-ignore function.alreadyNarrowedType (an int overflow turns the sum into a float)
        $leavesAt = \is_int($leavesAt) ? $leavesAt : PHP_INT_MAX;
        $now = Scope::now();

        // Sleeps once: a timer of the loop never fires early, so the slot is free once delay() returns.
        // A cancelled delay() throws before the call is counted.
        if ($leavesAt > $now) {
            delay(ns(min($leavesAt - $now, Scope::LONGEST_DURATION)));
        }

        $this->starts[$this->oldest] = max($leavesAt, Scope::now());
        // Every slot in turn: the other direction around the ring is the same ring.
        // @infection-ignore Plus
        $this->oldest = ($this->oldest + 1) % $this->limit;
    }
}
