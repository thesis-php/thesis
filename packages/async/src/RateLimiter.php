<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * Bounds how often calls start: an API that allows 10 requests a second. A {@see Semaphore} bounds how many run at once;
 * the two are independent and often used together.
 *
 * {@see LocalTokenBucketRateLimiter} is the in-process implementation. Others (a bucket in Redis shared by several processes)
 * implement this interface and must keep the contract of {@see wait()}.
 *
 * @api
 */
interface RateLimiter
{
    /**
     * Waits until the next call is allowed and counts it. There is nothing to release: the limit is on starts.
     *
     * Contract for implementations:
     * - wait through {@see suspend()} or primitives built on it, so that the wait is cancellable;
     * - a wait that throws {@see Cancelled} has not counted a call: its turn goes to the next caller;
     * - once a call is counted, return, even if the scope has been cancelled meanwhile.
     *
     * @throws Cancelled if the scope is cancelled while waiting
     */
    public function wait(): void;
}
