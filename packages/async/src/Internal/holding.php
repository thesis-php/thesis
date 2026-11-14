<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Thesis\Async\Lock;

/**
 * The common part of `Thesis\Async\synchronized()` and `Thesis\Async\synchronizedKey()`: runs $fn with $lock
 * recorded in $locks under $key, so that the fiber's reentrant calls find it, and releases it whatever $fn ends with.
 *
 * @internal
 * @template TKey
 * @template T
 * @param \ArrayAccess<TKey, Lock> $locks the locks the current fiber holds
 * @param TKey $key
 * @param callable(): T $fn
 * @return T
 */
function holding(\ArrayAccess $locks, mixed $key, Lock $lock, callable $fn): mixed
{
    $locks[$key] = $lock;

    try {
        return $fn();
    } finally {
        // Forgotten before the release: if it throws, the fiber must not stay a holder that takes no lock.
        unset($locks[$key]);
        $lock->release();
    }
}
