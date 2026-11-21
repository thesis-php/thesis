<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace;

use Thesis\Async\Instant;
use Thesis\Async\Trace\Ended;
use Thesis\Async\Trace\Event;
use Thesis\Async\Trace\GroupCreated;
use Thesis\Async\Trace\Joining;
use Thesis\Async\Trace\Resumed;
use Thesis\Async\Trace\ShieldCreated;
use Thesis\Async\Trace\Suspended;
use Thesis\Async\Trace\TaskCreated;
use Thesis\Async\Trace\TaskStarted;
use Thesis\Async\Trace\TimeoutCreated;
use Thesis\AsyncTrace\Internal\Describe;
use Thesis\AsyncTrace\Internal\Run;
use Thesis\Dispatcher;
use Thesis\Dispatcher\Subscriber;
use Time\Duration;
use function Thesis\Time\ns;
use function Thesis\Time\toNs;

/**
 * Finds blocking code, the most common bug of async PHP: code of a task that runs longer than $threshold without
 * a wait, while no other task can run. Such a run gets a {@see BlockingRun} in the dispatcher of the trace
 * when it ends, with the waits around it. Give it to the dispatcher of the trace:
 * `new Dispatcher([$detector, $listener])`.
 *
 * A run is of the event loop, not of a scope: one fiber runs at a time. It begins when a task starts, a wait
 * is over, the join of a group is over, or a group, a `timeout()` or a `shield()` is created in the root scope,
 * and ends at the next wait of any scope (a `timeout()` or a `shield()` waits in the fiber that creates it),
 * at the join of a group, at the end of a task or of a scope created in the root.
 * The detector uses the trace's monotonic timestamps. Under `virtualTime()`, CPU work advances
 * virtual time only when the caller uses `burnCpu()`.
 * Not seen: a run that never ends (an endless loop), code outside the scope tree (the callable of `observe()`
 * outside a group, a `timeout()` and a `shield()`, raw callbacks of the event loop): the waits of the root scope
 * have no events.
 *
 * It listens to the waits, so each costs a backtrace: a tool of debugging, not of every request in production.
 *
 * @api
 */
final class BlockingDetector implements Subscriber
{
    private readonly int $threshold;

    /**
     * The descriptions of the live scopes.
     *
     * @var array<positive-int, non-empty-string>
     */
    private array $scopes = [];

    /**
     * The live tasks: the end of a task ends its run, the end of a nested scope does not.
     *
     * @var array<positive-int, true>
     */
    private array $tasks = [];

    /**
     * The parents of the live groups with one: when the join of a group is over, a run of the parent begins.
     *
     * @var array<positive-int, positive-int>
     */
    private array $groups = [];

    /**
     * The live scopes created in the root, with what ends their run: their code is the first of the tree
     * in the fiber of the root, and the code after them is outside it.
     *
     * @var array<positive-int, non-empty-string>
     */
    private array $roots = [];

    /**
     * The waits of the suspended scopes, a run that wakes up begins there.
     *
     * @var array<positive-int, non-empty-string>
     */
    private array $waits = [];

    private ?Run $run = null;

    /**
     * @param Duration $threshold a run longer than it is reported
     */
    public function __construct(Duration $threshold)
    {
        $this->threshold = toNs($threshold);
    }

    #[\Override]
    public function listeners(): iterable
    {
        yield $this->onGroupCreated(...);
        yield $this->onTaskCreated(...);
        yield $this->onTimeoutCreated(...);
        yield $this->onShieldCreated(...);
        yield $this->onTaskStarted(...);
        yield $this->onSuspended(...);
        yield $this->onResumed(...);
        yield $this->onJoining(...);
        yield $this->onEnded(...);
    }

    private function onGroupCreated(GroupCreated $event): void
    {
        $this->scopes[$event->scope] = Describe::scope($event);

        if ($event->parent === null) {
            $this->beginInRoot($event, 'group');
        } else {
            $this->groups[$event->scope] = $event->parent;
        }
    }

    private function onTaskCreated(TaskCreated $event): void
    {
        $this->scopes[$event->scope] = Describe::scope($event);
        // A set: isset() reads only the key.
        // @infection-ignore TrueValue
        $this->tasks[$event->scope] = true;
    }

    private function onTimeoutCreated(TimeoutCreated $event): void
    {
        $this->scopes[$event->scope] = Describe::scope($event);

        if ($event->parent === null) {
            $this->beginInRoot($event, 'timeout');
        }
    }

    private function onShieldCreated(ShieldCreated $event): void
    {
        $this->scopes[$event->scope] = Describe::scope($event);

        if ($event->parent === null) {
            $this->beginInRoot($event, 'shield');
        }
    }

    private function onTaskStarted(TaskStarted $event): void
    {
        $this->begin($event->scope, $event->at, 'the start of the task');
    }

    private function onSuspended(Suspended $event, Dispatcher $dispatcher): void
    {
        $wait = $event->caller === null ? 'a wait of unknown code' : (string) $event->caller;
        $this->end($event, $wait, $dispatcher);
        $this->waits[$event->scope] = $wait;
    }

    private function onResumed(Resumed $event): void
    {
        $this->begin($event->scope, $event->at, $this->waits[$event->scope] ?? 'a wait of unknown code');
        unset($this->waits[$event->scope]);
    }

    private function onJoining(Joining $event, Dispatcher $dispatcher): void
    {
        $this->end($event, 'the join of the group', $dispatcher);
    }

    private function onEnded(Ended $event, Dispatcher $dispatcher): void
    {
        $scope = $event->scope;

        if (isset($this->tasks[$scope])) {
            $this->end($event, 'the end of the task', $dispatcher);
        } elseif (isset($this->roots[$scope])) {
            $this->end($event, $this->roots[$scope], $dispatcher);
        } elseif (isset($this->groups[$scope])) {
            // The join waits without `suspend()`, so no `Resumed` comes when the fiber of the parent goes on.
            $this->begin($this->groups[$scope], $event->at, 'the join of the group');
        }

        unset(
            $this->scopes[$scope],
            $this->tasks[$scope],
            $this->groups[$scope],
            $this->roots[$scope],
            $this->waits[$scope],
        );
    }

    /**
     * A scope created in the root runs in the fiber of the root, where no wait has an event: no `TaskStarted`
     * or `Resumed` begins its run. A scope created in another one goes on with the run of the fiber that creates it.
     *
     * @param non-empty-string $kind
     */
    private function beginInRoot(GroupCreated|TimeoutCreated|ShieldCreated $event, string $kind): void
    {
        $this->roots[$event->scope] = 'the end of the ' . $kind;
        $this->begin($event->scope, $event->at, 'the start of the ' . $kind);
    }

    /**
     * A run left open by a wait outside the scope tree has no known end: it is dropped, not reported.
     *
     * @param positive-int $scope
     * @param non-empty-string $from
     */
    private function begin(int $scope, Instant $at, string $from): void
    {
        $this->run = new Run(
            scope: $scope,
            what: $this->scopes[$scope] ?? '#' . $scope,
            at: $at,
            from: $from,
        );
    }

    /**
     * @param non-empty-string $to
     */
    private function end(Event $event, string $to, Dispatcher $dispatcher): void
    {
        $run = $this->run;

        if ($run === null) {
            return;
        }

        $this->run = null;
        $duration = toNs($event->at->since($run->at));

        if ($duration > $this->threshold) {
            $dispatcher->dispatch(new BlockingRun(
                scope: $run->scope,
                what: $run->what,
                at: $run->at,
                duration: ns($duration),
                from: $run->from,
                to: $to,
            ));
        }
    }
}
