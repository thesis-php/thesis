<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace\Internal;

use Thesis\AsyncTrace\CancellationTracker;

/**
 * A live scope as {@see CancellationTracker} keeps it.
 *
 * @internal
 */
final class TracedScope
{
    /**
     * Of a group: `Group::cancelTasks()`, which cancels its tasks but not its body.
     */
    public ?Cause $tasksCause = null;

    /**
     * Of a group: its daemons cancelled once its tasks are done.
     */
    public ?Cause $daemonsCause = null;

    /**
     * Of a group: its tasks that have failed, remembered until it ends.
     *
     * @var list<positive-int>
     */
    public array $failedTasks = [];

    /**
     * @param non-empty-string $what
     * @param ?positive-int $parent the group of a task
     * @param ?Cause $cause what has cancelled the scope itself: a cancellation that has reached it,
     *     or the one of its parent if it is created cancelled
     */
    public function __construct(
        public readonly string $what,
        public readonly ?int $parent,
        public readonly bool $task,
        public ?Cause $cause,
    ) {}
}
