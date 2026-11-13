<?php

declare(strict_types=1);

namespace Thesis\Async;

use Revolt\EventLoop;
use Thesis\Async\Internal\Context;

/**
 * Nursery: the only way to start a concurrent task. Obtained from {@see group()}.
 *
 * @api
 */
final class Group
{
    private ?\Throwable $error = null;

    private int $tasks = 0;

    private int $daemons = 0;

    /** @var array<int, Scope> */
    private array $daemonScopes = [];

    /** @var ?Waker<null> */
    private ?Waker $joiner = null;

    private function __construct(
        private readonly Scope $scope,
    ) {}

    /**
     * Implementation of {@see group()}.
     *
     * @internal
     * @template T
     * @param callable(self): T $body
     * @return T
     */
    public static function run(callable $body): mixed
    {
        $scope = new Scope(Scope::current());
        $group = new self($scope);
        $result = null;
        $outerCancellation = null;

        try {
            $result = Context::enter($scope, static fn() => $body($group));
        } catch (Cancelled $cancelled) {
            // Own cancellation (Group::cancel() or a failed task) is a regular way out of the body.
            // A foreign one is rethrown, but only after join().
            if ($cancelled->origin !== $scope) {
                $outerCancellation = $cancelled;
            }
        } catch (\Throwable $error) {
            $group->fail($error);
        }

        $error = $group->join();
        $scope->close();

        if ($error !== null) {
            throw $error;
        }

        if ($outerCancellation !== null) {
            throw $outerCancellation;
        }

        /** @var T */
        return $result;
    }

    /**
     * Starts a task the group waits for. Its failure cancels the whole group and is rethrown from {@see group()}.
     *
     * @template T
     * @param callable(): T $fn
     * @return Task<T>
     */
    public function go(callable $fn): Task
    {
        return $this->start($fn, daemon: false);
    }

    /**
     * Starts a background task (heartbeat, ping reader, metrics) the group does NOT wait for:
     * it is cancelled as soon as the body and all regular tasks are finished.
     *
     * @template T
     * @param callable(): T $fn
     * @return Task<T>
     */
    public function daemon(callable $fn): Task
    {
        return $this->start($fn, daemon: true);
    }

    /**
     * Cancels the body and all tasks. {@see group()} then returns normally.
     */
    public function cancel(): void
    {
        $this->scope->cancel();
    }

    private function fail(\Throwable $error): void
    {
        $this->error ??= $error;
        $this->scope->cancel();
    }

    /**
     * Uncancellable wait for all children: this is the structured concurrency guarantee.
     * Outer cancellation reaches the children through the scope tree, and the parent still waits for them.
     *
     * @return ?\Throwable the first failure
     */
    private function join(): ?\Throwable
    {
        while ($this->tasks > 0) {
            ($this->joiner = new Waker())->park();
        }

        foreach ($this->daemonScopes as $scope) {
            $scope->cancel();
        }

        while ($this->daemons > 0) {
            ($this->joiner = new Waker())->park();
        }

        return $this->error;
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return Task<T>
     */
    private function start(callable $fn, bool $daemon): Task
    {
        $scope = new Scope($this->scope);
        /** @var Task<T> */
        $task = new Task($scope);

        if ($daemon) {
            ++$this->daemons;
            $this->daemonScopes[spl_object_id($scope)] = $scope;
        } else {
            ++$this->tasks;
        }

        EventLoop::queue(function () use ($fn, $scope, $task, $daemon) {
            try {
                $task->settle(Context::enter($scope, $fn), null);
            } catch (Cancelled $cancelled) {
                // Cancellation is not a failure: it does not bring the group down.
                $task->settle(null, $cancelled);
            } catch (\Throwable $error) {
                $task->settle(null, $error);
                $this->fail($error);
            } finally {
                $scope->close();

                if ($daemon) {
                    --$this->daemons;
                    unset($this->daemonScopes[spl_object_id($scope)]);
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
