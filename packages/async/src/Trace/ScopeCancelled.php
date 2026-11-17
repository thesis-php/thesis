<?php

declare(strict_types=1);

namespace Thesis\Async\Trace;

use Thesis\Async\Instant;
use Thesis\SourceLocation\Caller;

/**
 * An owner has cancelled: a task by its handle, the tasks or everything of a group, a timeout by its deadline.
 * Every scope it reached is cancelled at the same moment, those under a shield are not.
 *
 * @api
 */
final readonly class ScopeCancelled implements Event
{
    /**
     * @param positive-int $scope what is cancelled: the task, the group (its tasks, everything on a failure,
     *     its daemons), the timeout
     * @param ?positive-int $by the scope that has cancelled it: the one that has called `Task::cancel()`
     *     or `cancelTasks()`, the one that has failed, the timeout itself, the group whose daemons it is;
     *     null for none (a group failed by the event loop)
     * @param list<positive-int> $reached the scopes that are cancelled now and were not before,
     *     in the order of creation
     * @param ?Caller $caller the call that has cancelled, outside the library: `Thesis\Async\Task::cancel`
     *     or `Thesis\Async\Group::cancelTasks` and where it is written; null for the other causes,
     *     where the library cancels and `$by` says why
     * @param ?\Throwable $error what has failed, for `Failure`: what the task or the body has thrown,
     *     or the error of the event loop; null for the other causes
     */
    public function __construct(
        public int $scope,
        public Instant $at,
        public CancelCause $cause,
        public ?int $by,
        public array $reached,
        public ?Caller $caller,
        public ?\Throwable $error,
    ) {}
}
