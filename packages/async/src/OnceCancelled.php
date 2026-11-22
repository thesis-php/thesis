<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * Thrown by {@see Once::await()} when cancellation interrupted the callback in another caller's scope.
 *
 * @api
 */
final class OnceCancelled extends \RuntimeException
{
    /**
     * @internal
     */
    public function __construct(Cancelled $previous)
    {
        parent::__construct('The Once callback was cancelled.', previous: $previous);
    }
}
