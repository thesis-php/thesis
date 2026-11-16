<?php

declare(strict_types=1);

namespace Thesis\Async\Trace;

/**
 * @api
 */
enum CancelCause
{
    /**
     * `Task::cancel()`.
     */
    case Handle;

    /**
     * `Group::cancelTasks()`.
     */
    case CancelTasks;

    /**
     * a task or the body has failed, and its group cancels everything.
     */
    case Failure;

    /**
     * the deadline of `timeout()` or `timeoutOr()`.
     */
    case Deadline;

    /**
     * the regular tasks of a group are done, and it cancels the daemons.
     */
    case DaemonsAfterTasks;
}
