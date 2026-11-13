<?php

declare(strict_types=1);

namespace Thesis\Async;

use Revolt\EventLoop\Suspension;

/**
 * One-shot handle that wakes exactly one wait.
 *
 * Unlike {@see Suspension}, which is shared by all waits of a fiber, a waker fires once:
 * late {@see resume()} and {@see throw()} calls are ignored instead of waking an unrelated wait.
 * It may also fire before the fiber is parked, in which case the wait returns immediately.
 *
 * @api
 * @template T
 * @phpstan-sealed Internal\WakerImpl
 */
interface Waker
{
    /**
     * @param T $value
     * @return bool whether this call won, false if the waker has already fired
     */
    public function resume(mixed $value = null): bool;

    /**
     * @return bool whether this call won, false if the waker has already fired
     */
    public function throw(\Throwable $error): bool;
}
