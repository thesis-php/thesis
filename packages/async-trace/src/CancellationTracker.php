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
use Thesis\AsyncTrace\Internal\Cause;
use Thesis\AsyncTrace\Internal\Describe;
use Thesis\AsyncTrace\Internal\TracedScope;
use Thesis\Dispatcher;
use Thesis\Dispatcher\Subscriber;

/**
 * Tells what has cancelled a scope, which `Cancelled` itself does not: a scope that ends as cancelled
 * gets a {@see CancelOrigin}, with the deadline, the call of `Task::cancel()` or `Group::cancelTasks()` and where
 * it is written, or the failure that has cancelled it. Give it to the dispatcher of the trace,
 * `new Dispatcher([$tracker, $listener])`: the report goes there, among the events. A listener
 * of the reports stands next to it, after the listeners of the events that should see an `Ended` before its report.
 *
 * It listens only to the scopes being created, cancelled and ended, not to the waits: those cost a backtrace each.
 * It keeps a short description of each live scope and forgets it when the scope ends. A failed task is kept
 * until its group ends: its failure may cancel the group right after its end.
 *
 * @api
 */
final class CancellationTracker implements Subscriber
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
            group: null,
            cause: $event->cancelled ? $this->causeOf($event->parent) : null,
        );
    }

    private function onTaskCreated(TaskCreated $event): void
    {
        $group = $this->scopes[$event->group] ?? null;
        $cause = null;

        if ($event->cancelled && $group !== null) {
            // The daemons are under the tasks: `cancelTasks()` cancels them too.
            $cause = $event->daemon
                ? $group->daemonsCause ?? $group->tasksCause ?? $group->cause
                : $group->tasksCause ?? $group->cause;
        }

        $this->scopes[$event->scope] = new TracedScope(
            what: Describe::scope($event),
            group: $event->group,
            cause: $cause,
        );
    }

    private function onTimeoutCreated(TimeoutCreated $event): void
    {
        $this->scopes[$event->scope] = new TracedScope(
            what: Describe::scope($event),
            group: null,
            cause: $event->cancelled ? $this->causeOf($event->parent) : null,
        );
    }

    private function onShieldCreated(ShieldCreated $event): void
    {
        // A shield is never created cancelled: it hides the cancellation of its ancestors.
        $this->scopes[$event->scope] = new TracedScope(
            what: Describe::scope($event),
            group: null,
            cause: null,
        );
    }

    private function onScopeCancelled(ScopeCancelled $event): void
    {
        $cause = new Cause(
            at: $event->at,
            kind: $event->cause,
            by: $event->by,
            reason: $this->reason($event),
        );
        $scope = $this->scopes[$event->scope] ?? null;

        if ($scope !== null && $event->cause === CancelCause::CancelTasks) {
            $scope->tasksCause = $cause;
        } elseif ($scope !== null && $event->cause === CancelCause::DaemonsAfterTasks) {
            $scope->daemonsCause = $cause;
        }

        foreach ($event->reached as $reached) {
            if (isset($this->scopes[$reached])) {
                $this->scopes[$reached]->cause = $cause;
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

        // Forgetting the failed tasks only frees memory: the ids are not reused, no later event names them.
        // @infection-ignore Foreach_
        foreach ($scope->failedTasks as $task) {
            unset($this->failed[$task]);
        }

        $error = $event->error;
        $cause = $scope->cause;

        if ($error instanceof Cancelled && $cause !== null) {
            $dispatcher->dispatch(new CancelOrigin(
                scope: $event->scope,
                what: $scope->what,
                at: $cause->at,
                cause: $cause->kind,
                by: $cause->by,
                reason: $cause->reason,
            ));
        }

        $group = $scope->group === null ? null : $this->scopes[$scope->group] ?? null;

        // A scope remembered as failed without having failed only takes memory until its group ends:
        // no event names a scope that has ended, but the one whose failure cancels the group.
        if (/* @infection-ignore LogicalAnd */ $error !== null && !$error instanceof Cancelled && $group !== null) {
            $this->failed[$event->scope] = $scope->what;
            $group->failedTasks[] = $event->scope;
        }
    }

    /**
     * @param ?positive-int $parent
     */
    private function causeOf(?int $parent): ?Cause
    {
        return $parent === null ? null : $this->scopes[$parent]->cause ?? null;
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

        // The ids are not reused: a scope is never both live and failed.
        // @infection-ignore Coalesce
        return $this->scopes[$id]->what ?? $this->failed[$id] ?? '#' . $id;
    }

    /**
     * @param non-empty-string $callee the name of the call that caused cancellation, used when the call site is unknown
     * @return non-empty-string
     */
    private static function call(string $callee, ScopeCancelled $event): string
    {
        return $event->caller === null ? $callee . '()' : (string) $event->caller;
    }
}
