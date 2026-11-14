<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * Nursery: the only way to start a concurrent task. Obtained from {@see group()} and valid until it returns.
 *
 * @api
 * @phpstan-sealed Internal\GroupImpl
 */
interface Group
{
    /**
     * Starts a task the group waits for. It begins to run at the body's next wait, not inside spawn().
     * Its failure cancels the body and every task and is rethrown from {@see group()}.
     *
     * @template T
     * @param callable(): T $task
     * @return Task<T>
     * @throws \LogicException if {@see group()} has already returned
     */
    public function spawn(callable $task): Task;

    /**
     * Starts a background task (heartbeat, ping reader, metrics) the group does NOT wait for:
     * it is cancelled as soon as the body and all regular tasks are finished. Its failure fails the group.
     *
     * @template T
     * @param callable(): T $task
     * @return Task<T>
     * @throws \LogicException if {@see group()} has already returned
     */
    public function daemon(callable $task): Task;

    /**
     * Cancels every task and daemon of the group, but not the body: the body is the owner of the group,
     * and {@see group()} returns its result. Work that has to be stoppable this way belongs in a task.
     *
     * It is sticky: a task started afterwards never runs, its handle is done as soon as it would have started,
     * and {@see Task::await()} throws {@see TaskCancelled}.
     */
    public function cancelTasks(): void;
}
