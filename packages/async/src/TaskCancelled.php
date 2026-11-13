<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * Thrown by {@see Task::await()} when the awaited task was cancelled and therefore has no result.
 *
 * It is a regular exception, not a {@see Cancelled}: the awaiting scope is not cancelled, it just lost a dependency.
 * Left uncaught in a group, it fails the group like any other error.
 *
 * @api
 */
final class TaskCancelled extends \RuntimeException
{
    /**
     * @internal
     */
    public function __construct(Cancelled $previous)
    {
        parent::__construct('The task was cancelled.', previous: $previous);
    }
}
