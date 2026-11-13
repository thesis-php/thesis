<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * A fixed number of permits: at most that many holders at a time. With one permit, it is a {@see Mutex}.
 *
 * A lock is needed only for a critical section that waits: a request and its response on a connection without
 * multiplexing, a message written as several frames. Code that does not wait between the two points
 * needs none: fibers switch only at waits. With more permits, it bounds concurrent access to a resource:
 * connections to a service, parallel downloads.
 *
 * Use {@see synchronized()} unless the lock has to outlive one call.
 *
 * {@see LocalSemaphore} is the in-process implementation. Others (a Redis key, a Postgres advisory lock, flock())
 * implement this interface and must keep the contract of {@see acquire()}.
 *
 * @api
 */
interface Semaphore
{
    /**
     * Waits until a permit is free and takes it. Not reentrant: a second acquire() by a holder waits
     * for another permit, forever if there is only one. {@see synchronized()} is reentrant.
     *
     * Contract for implementations:
     * - wait through {@see suspend()} or primitives built on it, so that the wait is cancellable;
     * - once a permit is taken, return the lock, even if the scope has been cancelled meanwhile:
     *   a taken permit must not be lost; if it cannot be returned, release it before throwing.
     *
     * @throws Cancelled if the scope is cancelled while waiting
     */
    public function acquire(): Lock;
}
