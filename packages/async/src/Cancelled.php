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
        return new self();
    }

    private function __construct()
    {
        parent::__construct('The scope is cancelled.');

        // Report the call to create() so the exception points at the interrupted wait.
        $caller = $this->getTrace()[0] ?? [];
        $this->file = $caller['file'] ?? $this->file;
        $this->line = $caller['line'] ?? $this->line;
    }
}
