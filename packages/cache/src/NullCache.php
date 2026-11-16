<?php

declare(strict_types=1);

namespace Thesis\Cache;

use Time\Duration;

/**
 * A {@see Cache} that stores nothing: every get() is a miss. For turning a cache off without changing the code
 * that uses it. Under an {@see AtomicCache}, every getOrCompute() computes, and concurrent ones of a key
 * compute one after another.
 *
 * @api
 * @template V
 * @implements Cache<V>
 */
final readonly class NullCache implements Cache
{
    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function set(string $key, mixed $value, ?Duration $ttl = null): void {}

    public function delete(string $key): void {}
}
