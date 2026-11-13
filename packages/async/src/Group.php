<?php

declare(strict_types=1);

namespace Thesis\Async;

use Revolt\EventLoop;

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

    /**
     * @internal
     */
    public function __construct(
        private readonly Scope $scope,
    ) {}

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

    /**
     * @internal
     */
    public function fail(\Throwable $error): void
    {
        $this->error ??= $error;
        $this->scope->cancel();
    }

    /**
     * Uncancellable wait for all children: this is the structured concurrency guarantee.
     * Outer cancellation reaches the children through the scope tree, and the parent still waits for them.
     *
     * @internal
     * @return ?\Throwable the first failure
     */
    public function join(): ?\Throwable
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

        EventLoop::queue(function () use ($fn, $scope, $task, $daemon): void {
            try {
                $task->settle($scope->enter($fn), null);
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
