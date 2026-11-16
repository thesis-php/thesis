<?php

declare(strict_types=1);

namespace Thesis\Cache;

use Thesis\Async\Cancelled;
use Thesis\Async\KeyedMutex;
use Thesis\Async\LocalKeyedMutex;
use Thesis\Async\shield;
use Thesis\Cache\Internal\Missing;
use Time\Duration;
use function Thesis\Async\synchronizedKey;

/**
 * A {@see Cache} that computes a missing value once for all who ask for it at the same time.
 *
 * The computation runs in the fiber of the caller that took the lock of the key, never in a task of its own:
 * cancelling that caller cancels the computation, releases the lock, and the next waiter computes the value itself.
 * The others are not failed by somebody else's cancellation, and the cache needs no owner of background work.
 *
 * set() and delete() take the lock of the key too: an invalidation waits for a computation in progress
 * and wins over it, instead of being overwritten by a value computed from the data before the change.
 * get() and a hit of getOrCompute() take no lock. Writes that bypass this object (to the inner cache directly)
 * lose that guarantee.
 *
 * A set() or delete() cancelled while it waits for the lock is not applied, and the computation in progress stores
 * its value. An invalidation that must happen runs under {@see shield()}.
 *
 * The lock is reentrant per fiber, not per task tree. Two ways to a deadlock (until a timeout of the callers):
 * a computation whose child task (group(), all()) touches the key the parent computes,
 * and two computations that nest the same two keys in opposite orders.
 *
 * The lock is a {@see KeyedMutex}: by default a {@see LocalKeyedMutex} of this object, which protects one process.
 * A distributed one (Redis) protects every process that shares the inner cache, if they all pass it.
 *
 * @api
 * @template V
 * @implements Cache<V>
 */
final readonly class AtomicCache implements Cache
{
    /**
     * @param Cache<V> $cache
     */
    public function __construct(
        private Cache $cache,
        private KeyedMutex $mutex = new LocalKeyedMutex(),
    ) {}

    /**
     * The value of $key, or the result of $compute stored with $ttl if there is none.
     *
     * A hit takes no lock. On a miss, takes the lock of $key and reads again (another caller may have stored
     * the value meanwhile), and only then computes, so concurrent callers of one key wait for the first and get
     * its value. A stored null is a hit.
     *
     * Reentrant within a fiber: $compute may call getOrCompute(), set() or delete() of any key, its own included.
     * What it stores under its own key is then replaced by its result.
     *
     * A failure of $compute, or of the set() of its result, is thrown as it is to its caller and nothing is stored:
     * the next waiter computes again. So does it with a zero or negative $ttl, which stores nothing:
     * concurrent callers then compute one after another, slower than without the lock.
     *
     * @param callable(string): V $compute gets the key
     * @return V
     * @throws Cancelled if the scope is cancelled while waiting for the lock or computing
     */
    public function getOrCompute(string $key, callable $compute, ?Duration $ttl = null): mixed
    {
        $value = $this->cache->get($key, Missing::Value);

        if ($value !== Missing::Value) {
            return $value;
        }

        return synchronizedKey($this->mutex, $key, function () use ($key, $compute, $ttl) {
            $value = $this->cache->get($key, Missing::Value);

            if ($value !== Missing::Value) {
                return $value;
            }

            $value = $compute($key);
            $this->cache->set($key, $value, $ttl);

            return $value;
        });
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->get($key, $default);
    }

    /**
     * Under the lock of $key: waits for a computation of $key in progress, then replaces its value.
     *
     * @throws Cancelled if the scope is cancelled while waiting for the lock
     */
    public function set(string $key, mixed $value, ?Duration $ttl = null): void
    {
        synchronizedKey($this->mutex, $key, fn() => $this->cache->set($key, $value, $ttl));
    }

    /**
     * Under the lock of $key: waits for a computation of $key in progress, then deletes its value.
     *
     * @throws Cancelled if the scope is cancelled while waiting for the lock
     */
    public function delete(string $key): void
    {
        synchronizedKey($this->mutex, $key, fn() => $this->cache->delete($key));
    }
}
