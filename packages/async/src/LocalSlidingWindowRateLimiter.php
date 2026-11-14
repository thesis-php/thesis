<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * In-process {@see RateLimiter} with a sliding window: at most $calls calls start within any $seconds seconds.
 * For a server that enforces exactly that, where a {@see LocalRateLimiter} would have to be set to half the rate:
 * a token bucket bounds the average, a window bounds every interval.
 *
 * It keeps the start times of the last $calls calls; a call waits until the oldest of them leaves the window.
 * So after a pause $calls calls start at once, and the next ones follow the pattern of the first window.
 * Memory is one float per call of the limit.
 *
 * Fair and cancellable in the same way as {@see LocalRateLimiter}: callers queue on a {@see LocalMutex},
 * only the first in the queue sleeps, and a caller cancelled while queued or sleeping counts no call.
 * {@see wait()} returns without suspending when the window has room and nobody is queued.
 *
 * @api
 */
final class LocalSlidingWindowRateLimiter implements RateLimiter
{
    private readonly LocalMutex $mutex;

    /** @var array<non-negative-int, float> start times of the last calls, a ring; a slot is missing until its first call */
    private array $starts = [];

    /** @var non-negative-int the oldest start in the ring */
    private int $oldest = 0;

    /**
     * @param positive-int $calls how many calls may start within the window
     * @param float $seconds the length of the window, > 0
     * @throws \ValueError if $seconds is not positive
     */
    public function __construct(
        private readonly int $calls,
        private readonly float $seconds,
    ) {
        if (!($seconds > 0)) {
            throw new \ValueError(\sprintf('LocalSlidingWindowRateLimiter needs a positive window, got %s.', $seconds));
        }

        $this->mutex = new LocalMutex();
    }

    public function wait(): void
    {
        $lock = $this->mutex->acquire();

        try {
            while (true) {
                $wait = ($this->starts[$this->oldest] ?? -INF) + $this->seconds - now();

                if ($wait <= 0) {
                    break;
                }

                // A cancelled delay() throws before the call is counted. A wake-up a little early only loops again.
                delay($wait);
            }

            $this->starts[$this->oldest] = now();
            $this->oldest = ($this->oldest + 1) % $this->calls;
        } finally {
            $lock->release();
        }
    }
}
