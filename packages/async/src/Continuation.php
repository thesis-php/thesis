<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * What is left of one suspended wait: resuming it delivers a value or an error to exactly that wait.
 * Obtained from {@see suspend()}, the way Kotlin's `suspendCancellableCoroutine` hands out a continuation.
 *
 * It is one-shot: late {@see resume()} and {@see throw()} calls are ignored instead of waking an unrelated wait: a timer and a cancellation
 * may race for the same continuation, and whoever is first wins.
 * It may also be resumed before the fiber is parked, in which case the wait returns immediately.
 *
 * @api
 * @template T
 * @phpstan-sealed Internal\ContinuationImpl
 */
interface Continuation
{
    /**
     * @param T $value
     * @return bool whether this call won, false if the continuation has already fired
     */
    public function resume(mixed $value = null): bool;

    /**
     * @return bool whether this call won, false if the continuation has already fired
     * @throws \ValueError if $error is {@see Cancelled}: it would be thrown in a scope that is not cancelled
     */
    public function throw(\Throwable $error): bool;
}
