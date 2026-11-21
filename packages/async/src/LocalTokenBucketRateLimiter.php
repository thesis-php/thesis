<?php

declare(strict_types=1);

namespace Thesis\Async;

use Thesis\Async\Internal\Scope;
use function Thesis\Time\ns;

/**
 * In-process {@see RateLimiter}: a token bucket. It holds up to $burst tokens and gains $perSecond of them a second;
 * each call takes a token. After a pause, $burst calls start at once. Then one call starts every
 * 1 / $perSecond seconds.
 * It bounds the average, not every window: within any T seconds at most $burst + $perSecond * T calls start,
 * and one more after a call the event loop has woken up late by less than a token interval: the schedule catches up,
 * so a real timer, a little late on every call, does not slow the rate down. A wake-up later than that has lost
 * a token to the full bucket, and the counting starts anew from it.
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
        if (is_nan($perSecond) || $perSecond <= 0) {
            throw new \ValueError(\sprintf(
                'LocalTokenBucketRateLimiter needs a positive rate, got %s.',
                is_nan($perSecond) ? 'NAN' : $perSecond,
            ));
        }

        $this->mutex = new LocalMutex();
    }

    public function wait(): void
    {
        synchronized($this->mutex, $this->take(...));
    }

    private function take(): void
    {
        $now = Scope::now();

        if ($this->tokenAt($this->taken) <= $now) {
            $this->fullAt = $now;
            $this->taken = 0;
        }

        // The caller sleeps once: a timer of the loop never fires early, so the token is there once delay() returns.
        // A cancelled delay() throws before the token is taken.
        $tokenAt = $this->tokenAt($this->taken - $this->burst + 1);

        if ($tokenAt > $now) {
            delay(ns(min($tokenAt - $now, Scope::LONGEST_DURATION)));
            $now = Scope::now();

            // The bucket has been full for a whole token interval (the loop was blocked): counted from the old
            // start, it would be full again right after this token, and $burst + 1 calls would start at once.
            // A wake-up late by less keeps the schedule: a timer is late on every call,
            // and the delays would add up.
            if ($this->tokenAt($this->taken + 1) <= $now) {
                $this->fullAt = $now;
                $this->taken = 0;
            }
        }

        ++$this->taken;
    }

    /**
     * When the $tokens-th token after {@see $fullAt} comes. Computed afresh from $fullAt, so the rounding of a token
     * interval that is not a whole number of nanoseconds does not add up.
     * Saturated like a deadline: a token centuries away never comes, one centuries ago has long come.
     */
    private function tokenAt(int $tokens): int
    {
        $offset = round($tokens * 1e9 / $this->perSecond);

        if ($offset >= PHP_INT_MAX) {
            return PHP_INT_MAX;
        }

        // Exactly PHP_INT_MIN is an int, and $fullAt plus it is as long ago as PHP_INT_MIN for the clock.
        if (/* @infection-ignore LessThanOrEqualTo */ $offset <= PHP_INT_MIN) {
            return PHP_INT_MIN;
        }

        $at = $this->fullAt + (int) $offset;

        // A negative $offset never overflows: $fullAt is a time of the clock then, not PHP_INT_MIN.
        // @phpstan-ignore function.alreadyNarrowedType (an int overflow turns the sum into a float)
        return \is_int($at) ? $at : PHP_INT_MAX;
    }
}
