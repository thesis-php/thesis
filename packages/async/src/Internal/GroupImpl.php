<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Revolt\EventLoop;
use Revolt\EventLoop\UncaughtThrowable;
use Thesis\Async\Cancelled;
use Thesis\Async\Group;
use Thesis\Async\Task;
use Thesis\Async\TaskCancelled;

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

    /** a regular task cut short by an outer cancellation: the work is not done, so group() must not return */
    private ?Cancelled $cancelled = null;

    private int $tasks = 0;

    private int $daemons = 0;

    private bool $closed = false;

    /** @var ?ContinuationImpl<null> */
    private ?ContinuationImpl $joiner = null;

    private function __construct(
        private readonly Scope $body,
        private readonly Scope $tasksScope,
        private readonly Scope $daemonsScope,
    ) {}

    /**
     * Implementation of `Thesis\Async\group()`.
     *
     * @template T
     * @param callable(Group): T $body
     * @return T
     */
    public static function run(callable $body): mixed
    {
        $bodyScope = new Scope(Scope::current());
        $tasksScope = new Scope($bodyScope);
        $daemonsScope = new Scope($tasksScope);
        $group = new self($bodyScope, $tasksScope, $daemonsScope);
        $result = null;

        try {
            $result = $bodyScope->run(static fn() => $body($group));
        } catch (Cancelled $cancelled) {
            // Either an outer cancellation or a consequence of a task failure. The tasks see it through the tree,
            // so there is nothing to cancel here: it is rethrown after join(), unless a failure takes precedence.
            $group->cancelled = $cancelled;
        } catch (\Throwable $error) {
            $group->fail($error);
        }

        try {
            $group->join();
        } finally {
            // The tasks and daemons scopes are only reachable through the body scope: they go away with it.
            $bodyScope->close();
        }

        if ($group->error !== null) {
            throw $group->error;
        }

        if ($group->cancelled !== null) {
            throw $group->cancelled;
        }

        /** @var T */
        return $result;
    }

    public function spawn(callable $fn): Task
    {
        return $this->start($this->tasksScope, $fn);
    }

    public function daemon(callable $fn): Task
    {
        return $this->start($this->daemonsScope, $fn);
    }

    public function cancelTasks(): void
    {
        $this->tasksScope->cancel();
    }

    private function fail(\Throwable $error): void
    {
        if ($this->error === null) {
            $this->error = $error;
        } else {
            Suppressed::add($this->error, $error);
        }

        $this->body->cancel();
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
                    $this->daemonsScope->cancel();
                }

                try {
                    ($this->joiner = new ContinuationImpl())->park();
                } catch (\Throwable $error) {
                    // Its {main} suspension has been abandoned by Revolt: the next round parks a fresh continuation,
                    // and a task finishing after a give-up has nothing to resume.
                    $this->joiner = null;

                    if ($error instanceof UncaughtThrowable) {
                        $this->fail($error);

                        continue;
                    }

                    if ($this->body->isCancelled()) {
                        // Revolt's error replaces the group's failure: it is not lost, unless it is the same deadlock.
                        if ($this->error !== null && $this->error !== $dry) {
                            Suppressed::add($error, $this->error);
                        }

                        throw $error;
                    }

                    $dry = $error;
                    $this->fail($error);
                }
            }
        } finally {
            $this->closed = true;
        }
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return Task<T>
     */
    private function start(Scope $parent, callable $fn): Task
    {
        if ($this->closed) {
            throw new \LogicException('The group is closed: a task can only be started until group() returns.');
        }

        $daemon = $parent === $this->daemonsScope;
        $scope = new Scope($parent);
        // Here, in the spawning fiber: the scope tree does not know where the task was spawned.
        $values = Context::capture();
        /** @var TaskImpl<T> */
        $task = new TaskImpl($scope);

        if ($daemon) {
            ++$this->daemons;
        } else {
            ++$this->tasks;
        }

        EventLoop::queue(function () use ($fn, $scope, $task, $daemon, $values) {
            try {
                // A task cancelled before it started does not run at all.
                $scope->throwIfCancelled();
                // Most tasks inherit nothing: they pay neither for a closure nor for a binding then.
                $task->complete($values === [] ? $scope->run($fn) : Context::run($values, static fn() => $scope->run($fn)));
            } catch (\Throwable $error) {
                if ($error instanceof Cancelled && $scope->isCancelled()) {
                    // Cancellation is not a failure: it does not bring the group down.
                    $task->fail(new TaskCancelled($error));

                    // But if it came from outside, the group's work is incomplete, even if the body never awaits.
                    if (!$daemon && $this->body->isCancelled()) {
                        $this->cancelled ??= $error;
                    }
                } else {
                    if ($error instanceof Cancelled) {
                        $error = new \LogicException(
                            'Cancelled escaped a task whose scope is not cancelled: it was caught elsewhere and rethrown.',
                            previous: $error,
                        );
                    }

                    $task->fail($error);
                    $this->fail($error);
                }
            } finally {
                $scope->close();

                if ($daemon) {
                    --$this->daemons;
                } else {
                    --$this->tasks;
                }

                $joiner = $this->joiner;
                $this->joiner = null;
                $joiner?->resume();
            }
        });

        return $task;
    }
}
