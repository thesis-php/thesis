<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * A {@see Semaphore} per key: at most that many holders of one key at a time, and keys do not wait for each other.
 * With one permit, it is a {@see KeyedMutex}.
 *
 * For resources that are many and not known in advance: a lock per user, per order, per file.
 *
 * Use {@see synchronizedKey()} unless the lock has to outlive one call.
 *
 * {@see LocalKeyedSemaphore} is the in-process implementation. Others (a Redis key, a Postgres advisory lock)
 * implement this interface and must keep the contract of {@see acquire()}.
 *
 * @api
 */
interface KeyedSemaphore
{
    /**
     * `acquire()` waits until a permit of $key is free and takes it. Not reentrant, like {@see Semaphore::acquire()}:
     * a second acquire() of the same key by a holder waits for another permit. {@see synchronizedKey()} is reentrant.
     *
     * Implementations follow the {@see Semaphore::acquire()} contract.
     *
     * @param array-key $key an id or a name; as in an array, a numeric string is the same key as its integer
     * @throws Cancelled if the scope is cancelled while waiting
     */
    public function acquire(int|string $key): Lock;
}
