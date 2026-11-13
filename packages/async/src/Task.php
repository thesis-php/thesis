<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * Handle of a child task started via {@see Group}.
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
     */
    public function cancel(): void;

    /**
     * @return T
     * @throws Cancelled if the awaiting scope or the task itself was cancelled
     * @throws \Throwable the task failure
     */
    public function await(): mixed;
}
