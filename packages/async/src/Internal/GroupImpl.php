<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Revolt\EventLoop;
use Revolt\EventLoop\UncaughtThrowable;
use Thesis\Async\Cancelled;
use Thesis\Async\Group;
use Thesis\Async\Task;
use Thesis\Async\TaskCancelled;
use Thesis\Async\Trace\CancelCause;

/**
 * Scope tree of a group:
 *
 *     body       — failure cancels it, and everything below
 *     └─ tasks   — Group::cancelTasks() cancels it: every task, but not the body
 *        ├─ task, task, ...
 *        └─ daemons — cancelled by join() once the regular tasks are done
 *           └─ daemon, daemon, ...
 *
 * @internal
 */
final class GroupImpl implements Group
{
    private ?\Throwable $error = null;

    /**
     * a regular task cut short by an outer cancellation: the work is not done, so group() must not return.
     */
    private ?Cancelled $cancelled = null;

    /**
     * whether {@see cancelTasks()} has cancelled the tasks, before anything else did.
     */
    private bool $tasksCancelled = false;

    private int $tasks = 0;

    private int $daemons = 0;

    private bool $closed = false;

    /**
     * @var ?ContinuationImpl<null>
     */
    private ?ContinuationImpl $joiner = null;

    /**
     * Implementation of `Thesis\Async\group()` and of the functions that run on a group.
     *
     * @template T
     * @param callable(self): T $body
     * @param non-empty-string $function the public function that creates the group, for the trace: `__FUNCTION__`
     * @param ?non-empty-string $name
     * @return T
     */
    public static function run(callable $body, string $function, ?string $name = null): mixed
    {
        $parent = Scope::current();
        $bodyScope = new Scope($parent);
        $tasksScope = new Scope($bodyScope);
        $daemonsScope = new Scope($tasksScope);
        $group = new self(bodyScope: $bodyScope, tasksScope: $tasksScope, daemonsScope: $daemonsScope);
        Scope::$tracer?->groupCreated($bodyScope, $tasksScope, $daemonsScope, $parent, $function, $name);
        $result = null;
        $error = null;

        try {
            $result = $bodyScope->run(static fn() => $body($group));
        } catch (\Throwable $error) {
        }

        // Before the failure below and those of the tasks during join() cancel the body scope:
        // a callback of the body is called only for a cancellation that came while the body ran.
        $bodyScope->endCallbacks();

        if ($error !== null) {
            if ($error instanceof Cancelled && $bodyScope->isCancelled) {
                // Either an outer cancellation or a consequence of a task failure. The tasks see it through the tree,
                // so there is nothing to cancel here: it is rethrown after join(), unless a failure takes precedence.
                $group->cancelled = $error;
            } else {
                if ($error instanceof Cancelled) {
                    // Not this group's: rethrown as is, it would leave a healthy scope as a cancellation.
                    $error = new \LogicException(
                        message: 'Cancelled escaped a group body whose scope is not cancelled: it was caught elsewhere and rethrown.',
                        previous: $error,
                    );
                }

                $group->fail($error, by: $bodyScope);
            }
        }

        Scope::$tracer?->joining($bodyScope);

        try {
            $group->join();
        } catch (\Throwable $error) {
            Scope::$tracer?->ended($bodyScope, null, $error);

            throw $error;
        } finally {
            // Each child holds its parent and is held by it: closing all three breaks the cycle,
            // so a group is freed by refcounting, without waiting for the cycle collector.
            $daemonsScope->close();
            $tasksScope->close();
            $bodyScope->close();
        }

        $error = $group->error ?? $group->cancelled;
        Scope::$tracer?->ended($bodyScope, $error === null ? $result : null, $error);

        if ($error !== null) {
            throw $error;
        }

        /** @var T */
        return $result;
    }

    private function __construct(
        private readonly Scope $bodyScope,
        private readonly Scope $tasksScope,
        private readonly Scope $daemonsScope,
    ) {}

    public function spawn(callable $task, ?string $name = null): Task
    {
        return $this->start($this->tasksScope, $task, $name);
    }

    public function daemon(callable $task, ?string $name = null): Task
    {
        return $this->start($this->daemonsScope, $task, $name);
    }

    /**
     * A task that the library runs in $wrapper: to catch the outcome of $task ({@see Tracer::settled()}),
     * or to call $task on each item (a worker of `each()` and `map()`). The trace gets $task, the code of the user.
     *
     * @template T
     * @param callable(): T $wrapper
     * @param callable(never): mixed $task
     * @param ?non-empty-string $name
     * @return Task<T>
     */
    public function spawnWrapped(callable $wrapper, callable $task, ?string $name): Task
    {
        return $this->start($this->tasksScope, $wrapper, $name, $task);
    }

    public function cancelTasks(): void
    {
        if (!$this->tasksScope->isCancelled) {
            $this->tasksCancelled = true;
            $this->tasksScope->cancel(CancelCause::CancelTasks, by: Scope::current());
        }
    }

    /**
     * @param ?Scope $by the scope whose code has failed, null for the event loop
     */
    private function fail(\Throwable $error, ?Scope $by): void
    {
        if ($this->error === null) {
            $this->error = $error;
        } else {
            Suppressed::add($this->error, $error);
        }

        $this->bodyScope->cancel(CancelCause::Failure, $by, $error);
    }

    /**
     * Uncancellable wait for all children: this is the structured concurrency guarantee.
     * Outer cancellation reaches the children through the scope tree, and the parent still waits for them.
     *
     * Tasks may start new tasks while the group is joining; they are waited for too.
     *
     * In {main}, the wait runs the event loop, and Revolt throws out of it in two cases. A foreign callback
     * has thrown ({@see UncaughtThrowable}): that is a failure, the tasks are cancelled and still joined.
     * Or the loop has run dry while the tasks are waiting: a deadlock. Failing the group cancels the tasks, and
     * a cancellable wait unwinds. But if the loop runs dry while the group is already cancelled (on the second
     * round at the latest), no cancellation can help any more: a task waits under a shield for something
     * that never comes. Then the join gives up and rethrows Revolt's error, which lists the stuck fibers.
     * The stuck tasks are orphaned, but the alternative is to hang forever. The body scope is closed anyway,
     * and so is the group: a stuck task that wakes up later cannot start new ones.
     *
     * @throws \Error if the event loop has run dry in {main} and cancelling the tasks has not helped
     */
    private function join(): void
    {
        /** the loop that ran dry on an earlier round: it is not a failure of its own when the join gives up */
        $dry = null;

        try {
            while ($this->tasks > 0 || $this->daemons > 0) {
                if ($this->tasks === 0) {
                    $this->daemonsScope->cancel(CancelCause::DaemonsAfterTasks, by: $this->bodyScope);
                }

                try {
                    ($this->joiner = new ContinuationImpl())->park();
                } catch (\Throwable $error) {
                    // Its {main} suspension has been abandoned by Revolt: the next round parks a fresh continuation,
                    // and a task finishing after a give-up has nothing to resume.
                    $this->joiner = null;

                    if ($error instanceof UncaughtThrowable) {
                        $this->fail($error, by: null);

                        continue;
                    }

                    if ($this->bodyScope->isCancelled) {
                        // Revolt's error replaces the group's failure: it is not lost, unless it is the same deadlock.
                        if ($this->error !== null && $this->error !== $dry) {
                            Suppressed::add($error, $this->error);
                        }

                        throw $error;
                    }

                    $dry = $error;
                    $this->fail($error, by: null);
                }
            }
        } finally {
            $this->closed = true;
        }
    }

    /**
     * @template T
     * @param callable(): T $task
     * @param ?non-empty-string $name
     * @param ?callable(never): mixed $traced what the trace gets as the task, if not $task itself
     * @return Task<T>
     */
    private function start(Scope $parent, callable $task, ?string $name, ?callable $traced = null): Task
    {
        if ($this->closed) {
            throw new \LogicException('The group is closed: a task can only be started until group() returns.');
        }

        $daemon = $parent === $this->daemonsScope;
        $scope = new Scope($parent);
        // Here, in the spawning fiber: the scope tree does not know where the task was spawned.
        $values = Context::capture();
        /** @var TaskImpl<T> */
        $handle = new TaskImpl($scope);
        Scope::$tracer?->taskCreated($scope, $this->bodyScope, $daemon, $name, $traced ?? $task);

        if ($daemon) {
            ++$this->daemons;
        } else {
            ++$this->tasks;
        }

        EventLoop::queue(function () use ($task, $scope, $handle, $daemon, $values) {
            try {
                // A task cancelled before it started does not run at all.
                $scope->throwIfCancelled();
                Scope::$tracer?->taskStarted($scope);
                // Most tasks inherit nothing: they pay neither for a closure nor for a binding then.
                $value = $values === []
                    ? $scope->run($task)
                    : Context::run($values, static fn() => $scope->run($task));
                Scope::$tracer?->ended($scope, $value, null);
                $handle->complete($value);
            } catch (\Throwable $error) {
                if ($error instanceof Cancelled && $scope->isCancelled) {
                    // Cancellation is not a failure: it does not bring the group down.
                    Scope::$tracer?->ended($scope, null, $error);
                    $handle->fail(new TaskCancelled($error));

                    // But if it came from outside, the group's work is incomplete, even if the body never awaits.
                    // Not if the group had cancelled the task before: an outer cancellation during its unwinding
                    // has cut nothing short.
                    if (
                        !$daemon
                        && !$this->tasksCancelled
                        && !$handle->cancelledByHandle
                        && $this->bodyScope->isCancelled
                    ) {
                        $this->cancelled ??= $error;
                    }
                } else {
                    if ($error instanceof Cancelled) {
                        $error = new \LogicException(
                            message: 'Cancelled escaped a task whose scope is not cancelled: it was caught elsewhere and rethrown.',
                            previous: $error,
                        );
                    }

                    Scope::$tracer?->ended($scope, null, $error);
                    $handle->fail($error);
                    // Before the failure cancels the task's own scope: the task has not been cancelled.
                    $scope->endCallbacks();
                    $this->fail($error, by: $scope);
                }
            } finally {
                $scope->close();

                if ($daemon) {
                    --$this->daemons;
                } else {
                    --$this->tasks;
                }

                // The joiner has something to do only when the last regular task is done (cancel the daemons),
                // or when the last daemon is done after it: waking it on every task would be a park per task.
                // Woken more often, it only parks again.
                if (/* @infection-ignore LogicalAnd */ $this->tasks === 0 && ($this->daemons === 0 || !$daemon)) {
                    $joiner = $this->joiner;
                    $this->joiner = null;
                    $joiner?->resume();
                }
            }
        });

        return $handle;
    }
}
