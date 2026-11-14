<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Thesis\Async\Deferred;
use Thesis\Async\Task;
use Thesis\Async\TaskCancelled;

/**
 * A task is its scope plus a {@see Deferred} result.
 *
 * @internal
 * @template-covariant T
 * @implements Task<T>
 */
final class TaskImpl implements Task
{
    #[\Override]
    public bool $isDone {
        get => $this->result->isDone;
    }

    /** @var Deferred<T> */
    private readonly Deferred $result;

    public function __construct(
        private readonly Scope $scope,
    ) {
        $this->result = new Deferred();
    }

    public function cancel(): void
    {
        $this->scope->cancel();
    }

    public function await(): mixed
    {
        if ($this->result->isDone) {
            // Not a cancellation point: the outcome of a finished task is returned as is, even in a cancelled scope.
            return $this->result->await();
        }

        try {
            return $this->result->await();
        } catch (TaskCancelled $cancelled) {
            // TaskCancelled is for an awaiter that is fine itself; one cancelled after the task woke it gets Cancelled.
            Scope::current()->throwIfCancelled();

            throw $cancelled;
        }
    }

    public function complete(mixed $value): void
    {
        /** @var T $value */
        $this->result->complete($value);
    }

    public function fail(\Throwable $error): void
    {
        $this->result->fail($error);
    }
}
