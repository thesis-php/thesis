<?php

declare(strict_types=1);

namespace Thesis\Async;

use Thesis\Async\Internal\Scope;
use function Thesis\Time\ns;

/**
 * In-process {@see RateLimiter}: a token bucket. It holds up to $burst tokens and gains $perSecond of them a second;
 * a call takes one. After a pause, $burst calls start at once, then one every 1 / $perSecond seconds.
 * It bounds the average, not every window: within any T seconds at most $burst + $perSecond * T calls start.
 * For a server that rejects more than N calls in any T seconds, use {@see LocalSlidingWindowRateLimiter}.
 *
 * There is no timer that refills the bucket and no clock read until the first call: the limiter keeps the absolute
 * time at which the bucket would be full again, and a call compares it with the clock ({@see Instant::now()}).
 * Callers queue on a {@see LocalMutex}, so it is fair in the same way: tokens go in the order of arrival,
 * and only the first in the queue sleeps until the next token. A caller cancelled while queued or sleeping
 * takes no token, and the next one gets it on time.
 *
 * {@see wait()} returns without suspending when a token is available and nobody is queued,
 * like {@see LocalSemaphore::acquire()} of a free permit: it is not a cancellation point then.
 *
 * @api
 */
final class LocalTokenBucketRateLimiter implements RateLimiter
{
    private readonly LocalMutex $mutex;

    /**
     * @var int the time the counting started at, in nanoseconds of the clock: when the bucket was last full
     */
    private int $fullAt = PHP_INT_MIN;

    /**
     * @var non-negative-int tokens taken since then; the bucket is full again at {@see tokenAt()} of them
     */
    private int $taken = 0;

    /**
     * @param float $perSecond how many calls a second may start on average, > 0
     * @param positive-int $burst how many calls may start at once after a pause; the bucket starts full
     * @throws \ValueError if $perSecond is not positive
     */
    public function __construct(
        private readonly float $perSecond,
        private readonly int $burst,
    ) {
        if (!($perSecond > 0)) {
            throw new \ValueError(\sprintf(
                'LocalTokenBucketRateLimiter needs a positive rate, got %s.',
                is_nan($perSecond) ? 'NAN' : $perSecond,
            ));
        }

        $this->mutex = new LocalMutex();
    }

    public function wait(): void
    {
        $lock = $this->mutex->acquire();

        try {
            $now = Scope::now();

            if ($this->tokenAt($this->taken) <= $now) {
                $this->fullAt = $now;
                $this->taken = 0;
            }

            // The caller sleeps once and does not compare the clock again: Revolt's timers are float seconds,
            // and a wake-up a hair early would loop on a delay too small to move the clock.
            // A cancelled delay() throws before the token is taken.
            $wait = $this->tokenAt($this->taken - $this->burst + 1) - $now;

            if ($wait > 0) {
                delay(ns($wait));
            }

            ++$this->taken;
        } finally {
            $lock->release();
        }
    }

    /**
     * When the $tokens-th token after {@see $fullAt} comes. Computed afresh from $fullAt, so the rounding of a token
     * interval that is not a whole number of nanoseconds does not add up.
     */
    private function tokenAt(int $tokens): int
    {
        return $this->fullAt + (int) round($tokens * 1e9 / $this->perSecond);
    }
}
