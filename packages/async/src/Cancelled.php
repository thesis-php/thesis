<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * Thrown at a suspension point while the current scope is cancelled.
 *
 * Extends {@see \Error} on purpose: a typical `catch (\Exception)` retry loop must not swallow cancellation.
 * Even if it is swallowed via `catch (\Throwable)`, the next suspension point in the same scope throws it again.
 *
 * Its trace points at the wait that was interrupted.
 *
 * @api
 */
final class Cancelled extends \Error
{
    /**
     * @internal
     */
    public function __construct()
    {
        parent::__construct('The scope is cancelled.');
    }
}
