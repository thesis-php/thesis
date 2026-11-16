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
 * It cannot be constructed outside the library: a Cancelled thrown by hand where nothing is cancelled would end
 * code as cancelled that was not. Rethrow the one caught instead.
 *
 * @api
 */
final class Cancelled extends \Error
{
    /**
     * @internal thrown only by the library, where the current scope is cancelled
     */
    public static function create(): self
    {
        $cancelled = new self();
        // Where the library throws it, not this line: with the trace, it points at the wait that was interrupted.
        $caller = $cancelled->getTrace()[0] ?? [];
        $cancelled->file = $caller['file'] ?? $cancelled->file;
        $cancelled->line = $caller['line'] ?? $cancelled->line;

        return $cancelled;
    }

    private function __construct()
    {
        parent::__construct('The scope is cancelled.');
    }
}
