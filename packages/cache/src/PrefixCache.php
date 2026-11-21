<?php

declare(strict_types=1);

namespace Thesis\Cache;

use Time\Duration;

/**
 * A {@see Cache} that adds a prefix to every key of the inner cache: several caches over one store
 * (`users:`, `orders:`) do not see each other's keys.
 *
 * The prefix is prepended as is, so end it with a separator that no other prefix of the store starts with:
 * Without a separator, `user` with the key `s1` collides with `users` with the key `1`.
 *
 * Over it, an {@see AtomicCache} locks the key without the prefix: AtomicCaches that share a distributed mutex
 * over different prefixes wait for each other on equal keys. Give each one a mutex of its own, or put the prefix
 * into the keys instead.
 *
 * @api
 * @template V
 * @implements Cache<V>
 */
final readonly class PrefixCache implements Cache
{
    /**
     * @param Cache<V> $cache
     */
    public function __construct(
        private Cache $cache,
        public string $prefix,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->get($this->prefix . $key, $default);
    }

    public function set(string $key, mixed $value, ?Duration $ttl = null): void
    {
        $this->cache->set($this->prefix . $key, $value, $ttl);
    }

    public function delete(string $key): void
    {
        $this->cache->delete($this->prefix . $key);
    }
}
