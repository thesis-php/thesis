<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Thesis\Async\Continuation;
use Thesis\Async\Task;
use Thesis\Async\TaskCancelled;
use function Thesis\Async\suspend;

/**
 * @internal
 * @template-covariant T
 * @implements Task<T>
 */
final class TaskImpl implements Task
{
    #[\Override]
    public private(set) bool $isDone = false;

    /** @var ?T */
    private mixed $value = null;

    private ?\Throwable $error = null;

    /** @var array<int, Continuation<null>> */
    private array $waiters = [];

    public function __construct(
        private readonly Scope $scope,
    ) {}

    public function cancel(): void
    {
        $this->scope->cancel();
    }

    public function await(): mixed
    {
        if (!$this->isDone) {
            suspend(function (Continuation $continuation) {
                /** @var Continuation<null> $continuation */
                $id = spl_object_id($continuation);
                $this->waiters[$id] = $continuation;

                return function () use ($id) {
                    unset($this->waiters[$id]);
                };
            });
        }

        if ($this->error instanceof TaskCancelled) {
            // TaskCancelled is for an awaiter that is fine itself; a cancelled one gets Cancelled.
            Scope::current()->checkpoint();
        }

        if ($this->error !== null) {
            throw $this->error;
        }

        /** @var T */
        return $this->value;
    }

    public function settle(mixed $value, ?\Throwable $error): void
    {
        $this->isDone = true;
        /** @var ?T $value */
        $this->value = $value;
        $this->error = $error;

        $waiters = $this->waiters;
        $this->waiters = [];

        foreach ($waiters as $waiter) {
            $waiter->resume();
        }
    }
}
