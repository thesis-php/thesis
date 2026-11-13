<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Revolt\EventLoop;
use Thesis\Async\Cancelled;
use Thesis\Async\Group;
use Thesis\Async\Task;
use Thesis\Async\TaskCancelled;

/**
 * Scope tree of a group:
 *
 *     body       — failure cancels it, and everything below
 *     └─ tasks   — Group::cancel() cancels it: every task, but not the body
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

        $group->join();
        $daemonsScope->close();
        $tasksScope->close();
        $bodyScope->close();

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

    public function cancel(): void
    {
        $this->tasksScope->cancel();
    }

    private function fail(\Throwable $error): void
    {
        $this->error ??= $error;
        $this->body->cancel();
    }

    /**
     * Uncancellable wait for all children: this is the structured concurrency guarantee.
     * Outer cancellation reaches the children through the scope tree, and the parent still waits for them.
     *
     * Tasks may start new tasks while the group is joining; they are waited for too.
     */
    private function join(): void
    {
        while ($this->tasks > 0 || $this->daemons > 0) {
            if ($this->tasks === 0) {
                $this->daemonsScope->cancel();
            }

            ($this->joiner = new ContinuationImpl())->park();
        }

        $this->closed = true;
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
        /** @var TaskImpl<T> */
        $task = new TaskImpl($scope);

        if ($daemon) {
            ++$this->daemons;
        } else {
            ++$this->tasks;
        }

        EventLoop::queue(function () use ($fn, $scope, $task, $daemon) {
            try {
                // A task cancelled before it started does not run at all.
                $scope->checkpoint();
                $task->settle($scope->run($fn), null);
            } catch (\Throwable $error) {
                if ($error instanceof Cancelled && $scope->isCancelled()) {
                    // Cancellation is not a failure: it does not bring the group down.
                    $task->settle(null, new TaskCancelled($error));

                    // But if it came from outside, the group's work is incomplete, even if the body never awaits.
                    if (!$daemon && $this->body->isCancelled()) {
                        $this->cancelled ??= $error;
                    }
                } else {
                    $task->settle(null, $error);
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
