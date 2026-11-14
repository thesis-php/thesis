<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * In-process {@see RateLimiter}: a token bucket. It holds up to $burst tokens and gains $perSecond of them a second;
 * a call takes one. After a pause, $burst calls start at once, then one every 1 / $perSecond seconds.
 * It bounds the average, not every window: within any T seconds at most $burst + $perSecond * T calls start.
 * For a server that rejects more than N calls in any T seconds, use {@see LocalSlidingWindowRateLimiter}.
 *
 * There is no timer that refills the bucket: the tokens are recomputed from the clock ({@see now()}) when a call comes.
 * Callers queue on a {@see LocalMutex}, so it is fair in the same way: tokens go in the order of arrival,
 * and only the first in the queue sleeps until the next token. A caller cancelled while queued or sleeping
 * takes no token, and the next one gets it on time.
 *
 * {@see wait()} returns without suspending when a token is available and nobody is queued,
 * like {@see LocalSemaphore::acquire()} of a free permit: it is not a cancellation point then.
 *
 * @api
 */
final class LocalRateLimiter implements RateLimiter
{
    private readonly LocalMutex $mutex;

    private float $tokens;

    private float $refilledAt;

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
            throw new \ValueError(\sprintf('LocalRateLimiter needs a positive rate, got %s.', $perSecond));
        }

        $this->mutex = new LocalMutex();
        $this->tokens = $burst;
        $this->refilledAt = now();
    }

    public function wait(): void
    {
        $lock = $this->mutex->acquire();

        try {
            while (true) {
                $now = now();
                $this->tokens = min($this->burst, $this->tokens + ($now - $this->refilledAt) * $this->perSecond);
                $this->refilledAt = $now;

                if ($this->tokens >= 1) {
                    --$this->tokens;

                    return;
                }

                // A cancelled delay() throws before the token is taken. A wake-up a little early only loops again.
                delay((1 - $this->tokens) / $this->perSecond);
            }
        } finally {
            $lock->release();
        }
    }
}
