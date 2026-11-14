<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * Handle of a task started via {@see Group}.
 *
 * @api
 * @template-covariant T
 * @phpstan-sealed Internal\TaskImpl
 */
interface Task
{
    public bool $isDone { get; }

    /**
     * Cancels this task only; its group and siblings keep running.
     * A task cancelled before it started never runs.
     */
    public function cancel(): void;

    /**
     * Does not suspend if the task is already done: its outcome is returned or thrown as is even in a cancelled scope,
     * so it is not a cancellation point then, and it does not yield to the event loop.
     *
     * A {@see TaskCancelled} does not tell a cancelled task from one that failed with the TaskCancelled
     * of another task it awaited and let through, as with Kotlin's and Java's cancellation exceptions.
     *
     * @return T
     * @throws Cancelled if the awaiting scope is cancelled
     * @throws TaskCancelled if the task was cancelled
     * @throws \Throwable the task failure
     */
    public function await(): mixed;
}
