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
     * Starts a task the group waits for. Its failure cancels the body and every task and is rethrown from {@see group()}.
     *
     * @template T
     * @param callable(): T $fn
     * @return Task<T>
     * @throws \LogicException if {@see group()} has already returned
     */
    public function go(callable $fn): Task;

    /**
     * Starts a background task (heartbeat, ping reader, metrics) the group does NOT wait for:
     * it is cancelled as soon as the body and all regular tasks are finished. Its failure fails the group.
     *
     * @template T
     * @param callable(): T $fn
     * @return Task<T>
     * @throws \LogicException if {@see group()} has already returned
     */
    public function daemon(callable $fn): Task;

    /**
     * Cancels every task of the group, including the ones started later. The body is not cancelled:
     * it is the owner of the group, and {@see group()} returns its result.
     * Work that has to be stoppable this way belongs in a task, not in the body.
     */
    public function cancel(): void;
}
