<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * Node of the cancellation tree. Every fiber always runs inside some scope.
 *
 * @api
 * @phpstan-sealed Internal\ScopeImpl
 */
interface Scope
{
    /** Whether this scope or a visible ancestor is cancelled. */
    public bool $isCancelled { get; }

    /**
     * Explicit cancellation point for CPU-bound loops that never suspend.
     *
     * @throws Cancelled
     */
    public function checkpoint(): void;

    /**
     * @throws \LogicException for the root scope: its cancellation would never be lifted
     */
    public function cancel(): void;

    /**
     * The listener is called at most once, synchronously inside {@see cancel()},
     * or immediately if the scope is already cancelled. It must not throw.
     *
     * @param callable(self): void $listener receives the cancelled scope
     * @return \Closure(): void unsubscribe
     */
    public function onCancel(callable $listener): \Closure;
}
