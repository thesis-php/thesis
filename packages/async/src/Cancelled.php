<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * Thrown at a suspension point when the current scope is cancelled.
 *
 * Extends {@see \Error} on purpose: a typical `catch (\Exception)` retry loop must not swallow cancellation.
 * Even if it is swallowed via `catch (\Throwable)`, the next suspension point in the same scope throws it again.
 *
 * @api
 */
final class Cancelled extends \Error
{
    /**
     * @internal
     */
    public function __construct(
        /** The outermost cancelled scope: lets {@see timeout()} tell its own deadline from an outer cancellation. */
        public readonly Scope $origin,
    ) {
        parent::__construct('Scope was cancelled.');
    }
}
