<?php

declare(strict_types=1);

namespace Thesis\Cache;

use Thesis\Async\Cancelled;
use Time\Duration;

/**
 * A key-value store whose entries may expire.
 *
 * Every method may suspend the fiber (a remote cache does I/O) and throws {@see Cancelled}
 * if the scope is cancelled meanwhile. A failure of the storage is thrown as the exception of the implementation:
 * a cache that hides failures is a decorator the application chooses, not a default.
 *
 * A cancelled set() or delete() of a remote cache may or may not have been applied: the request may have reached
 * the store before the cancellation.
 *
 * A key is any string: empty, binary, of any length. An implementation over a store that limits keys
 * (Memcached: 250 bytes without spaces and control characters) encodes them, never rejects.
 *
 * There is no has(): between has() and get() another fiber may delete the entry.
 * A stored null is told from a miss by a default of its own: `$cache->get($key, $missing) === $missing`.
 *
 * @api
 * @template V
 */
interface Cache
{
    /**
     * The value of $key, or $default if there is none or it has expired. A read does not prolong the TTL.
     *
     * @template D
     * @param D $default
     * @return V|D
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Stores $value under $key, replacing the previous one and its TTL.
     *
     * Any positive TTL is accepted. One below the resolution of the store is rounded up to it, never down to zero;
     * one beyond what the store can represent keeps the entry as long as the store can.
     *
     * @param V $value
     * @param ?Duration $ttl null keeps the entry until it is deleted or evicted; zero or negative deletes it
     */
    public function set(string $key, mixed $value, ?Duration $ttl = null): void;

    /**
     * Deletes $key. Deleting a missing key is not an error.
     */
    public function delete(string $key): void;
}
