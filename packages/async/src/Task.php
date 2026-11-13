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
     * @return T
     * @throws Cancelled if the awaiting scope is cancelled
     * @throws TaskCancelled if the task was cancelled
     * @throws \Throwable the task failure
     */
    public function await(): mixed;
}
