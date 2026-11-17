<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace;

use Thesis\Async\Cancelled;
use Thesis\Async\Trace\CancelCause;
use Thesis\Async\Trace\Ended;
use Thesis\Async\Trace\GroupCreated;
use Thesis\Async\Trace\ScopeCancelled;
use Thesis\Async\Trace\ShieldCreated;
use Thesis\Async\Trace\TaskCreated;
use Thesis\Async\Trace\TimeoutCreated;
use Thesis\AsyncTrace\Internal\Cancellation;
use Thesis\AsyncTrace\Internal\Describe;
use Thesis\AsyncTrace\Internal\TracedScope;
use Thesis\Dispatcher;
use Thesis\Dispatcher\Subscriber;

/**
 * Tells what has cancelled a scope, which `Cancelled` itself does not: a scope that ends as cancelled
 * gets a {@see CancelledBy}, with the deadline, the call of `Task::cancel()` or `Group::cancelTasks()` and where
 * it is written, or the failure that has cancelled it. Give it to the dispatcher of the trace,
 * `new Dispatcher([$whoCancelled, $listener])`: the report goes there, among the events. A listener
 * of the reports stands next to it, after the listeners of the events that should see an `Ended` before its report.
 *
 * It listens only to the scopes being created, cancelled and ended, not to the waits: those cost a backtrace each.
 * It keeps a short description of each live scope and forgets it when the scope ends. A failed task is kept
 * until its group ends: its failure may cancel the group right after its end.
 *
 * @api
 */
final class WhoCancelled implements Subscriber
{
    /**
     * @var array<positive-int, TracedScope>
     */
    private array $scopes = [];

    /**
     * The descriptions of the failed tasks whose groups have not ended yet.
     *
     * @var array<positive-int, non-empty-string>
     */
    private array $failed = [];

    #[\Override]
    public function listeners(): iterable
    {
        yield $this->onGroupCreated(...);
        yield $this->onTaskCreated(...);
        yield $this->onTimeoutCreated(...);
        yield $this->onShieldCreated(...);
        yield $this->onScopeCancelled(...);
        yield $this->onEnded(...);
    }

    private function onGroupCreated(GroupCreated $event): void
    {
        $this->scopes[$event->scope] = new TracedScope(
            what: Describe::scope($event),
            parent: $event->parent,
            task: false,
            cancellation: $event->cancelled ? $this->cancellationOf($event->parent) : null,
        );
    }

    private function onTaskCreated(TaskCreated $event): void
    {
        $group = $this->scopes[$event->group] ?? null;
        $cancellation = null;

        if ($event->cancelled && $group !== null) {
            $cancellation = ($event->daemon ? $group->daemonsCancellation : $group->tasksCancellation)
                ?? $group->cancellation;
        }

        $this->scopes[$event->scope] = new TracedScope(
            what: Describe::scope($event),
            parent: $event->group,
            task: true,
            cancellation: $cancellation,
        );
    }

    private function onTimeoutCreated(TimeoutCreated $event): void
    {
        $this->scopes[$event->scope] = new TracedScope(
            what: Describe::scope($event),
            parent: $event->parent,
            task: false,
            cancellation: $event->cancelled ? $this->cancellationOf($event->parent) : null,
        );
    }

    private function onShieldCreated(ShieldCreated $event): void
    {
        $this->scopes[$event->scope] = new TracedScope(
            what: Describe::scope($event),
            parent: $event->parent,
            task: false,
            cancellation: $event->cancelled ? $this->cancellationOf($event->parent) : null,
        );
    }

    private function onScopeCancelled(ScopeCancelled $event): void
    {
        $cancellation = new Cancellation(
            at: $event->at,
            cause: $event->cause,
            by: $event->by,
            reason: $this->reason($event),
        );
        $scope = $this->scopes[$event->scope] ?? null;

        if ($scope !== null && $event->cause === CancelCause::CancelTasks) {
            $scope->tasksCancellation = $cancellation;
        } elseif ($scope !== null && $event->cause === CancelCause::DaemonsAfterTasks) {
            $scope->daemonsCancellation = $cancellation;
        }

        foreach ($event->reached as $reached) {
            if (isset($this->scopes[$reached])) {
                $this->scopes[$reached]->cancellation ??= $cancellation;
            }
        }
    }

    private function onEnded(Ended $event, Dispatcher $dispatcher): void
    {
        $scope = $this->scopes[$event->scope] ?? null;

        if ($scope === null) {
            return;
        }

        unset($this->scopes[$event->scope]);

        foreach ($scope->failedTasks as $task) {
            unset($this->failed[$task]);
        }

        $error = $event->error;
        $cancellation = $scope->cancellation;

        if ($error instanceof Cancelled && $cancellation !== null) {
            $dispatcher->dispatch(new CancelledBy(
                scope: $event->scope,
                what: $scope->what,
                at: $cancellation->at,
                cause: $cancellation->cause,
                by: $cancellation->by,
                reason: $cancellation->reason,
            ));

            return;
        }

        $group = $scope->parent === null ? null : $this->scopes[$scope->parent] ?? null;

        if ($error !== null && !$error instanceof Cancelled && $scope->task && $group !== null) {
            $this->failed[$event->scope] = $scope->what;
            $group->failedTasks[] = $event->scope;
        }
    }

    /**
     * @param ?positive-int $parent
     */
    private function cancellationOf(?int $parent): ?Cancellation
    {
        return $parent === null ? null : $this->scopes[$parent]->cancellation ?? null;
    }

    /**
     * @return non-empty-string
     */
    private function reason(ScopeCancelled $event): string
    {
        $by = $this->describe($event->by);

        return match ($event->cause) {
            CancelCause::Handle => self::call('Thesis\Async\Task::cancel', $event) . ' in ' . $by,
            CancelCause::CancelTasks => self::call('Thesis\Async\Group::cancelTasks', $event) . ' in ' . $by,
            CancelCause::Failure => 'the failure of ' . ($event->by === null ? 'the event loop' : $by)
                . ($event->error === null ? '' : ': ' . Describe::error($event->error)),
            CancelCause::Deadline => 'the deadline of ' . $by,
            CancelCause::DaemonsAfterTasks => 'the end of the tasks of ' . $by,
        };
    }

    /**
     * @param ?positive-int $id
     * @return non-empty-string
     */
    private function describe(?int $id): string
    {
        if ($id === null) {
            return 'the root scope';
        }

        return $this->scopes[$id]->what ?? $this->failed[$id] ?? '#' . $id;
    }

    /**
     * @param non-empty-string $callee the call of the cause if the event does not know where it is written
     * @return non-empty-string
     */
    private static function call(string $callee, ScopeCancelled $event): string
    {
        return $event->caller === null ? $callee . '()' : (string) $event->caller;
    }
}
