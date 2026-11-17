<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace;

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
 * a wait, while no other task can run. Such a run gets a {@see Blocked} in the dispatcher of the trace when it ends,
 * with the waits around it. Give it to the dispatcher of the trace: `new Dispatcher([$detector, $listener])`.
 *
 * A run is of the event loop, not of a scope: one fiber runs at a time. It begins when a task starts or a wait
 * is over and ends at the next wait of any scope (a `timeout()` or a `shield()` waits in the fiber of its task),
 * at the join of a group or at the end of a task. The time is that of the events: the monotonic clock,
 * or the virtual one under `VirtualTime\run()`, where CPU work takes no time but `VirtualTime\advance()`.
 * Not seen: a run that never ends (an endless loop), code after the join of a group up to its next wait,
 * code outside the scope tree (the callable of `observe()` outside a group, raw callbacks of the event loop):
 * the waits of the root scope have no events.
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
    }

    private function onTaskCreated(TaskCreated $event): void
    {
        $this->scopes[$event->scope] = Describe::scope($event);
        $this->tasks[$event->scope] = true;
    }

    private function onTimeoutCreated(TimeoutCreated $event): void
    {
        $this->scopes[$event->scope] = Describe::scope($event);
    }

    private function onShieldCreated(ShieldCreated $event): void
    {
        $this->scopes[$event->scope] = Describe::scope($event);
    }

    private function onTaskStarted(TaskStarted $event): void
    {
        $this->begin($event, 'the start of the task');
    }

    private function onSuspended(Suspended $event, Dispatcher $dispatcher): void
    {
        $wait = $event->caller === null ? 'a wait of unknown code' : (string) $event->caller;
        $this->end($event, $wait, $dispatcher);
        $this->waits[$event->scope] = $wait;
    }

    private function onResumed(Resumed $event): void
    {
        $this->begin($event, $this->waits[$event->scope] ?? 'a wait of unknown code');
        unset($this->waits[$event->scope]);
    }

    private function onJoining(Joining $event, Dispatcher $dispatcher): void
    {
        $this->end($event, 'the join of the group', $dispatcher);
    }

    private function onEnded(Ended $event, Dispatcher $dispatcher): void
    {
        if (isset($this->tasks[$event->scope])) {
            $this->end($event, 'the end of the task', $dispatcher);
        }

        unset($this->scopes[$event->scope], $this->tasks[$event->scope], $this->waits[$event->scope]);
    }

    /**
     * A run left open by a wait outside the scope tree has no known end: it is dropped, not reported.
     *
     * @param non-empty-string $from
     */
    private function begin(TaskStarted|Resumed $event, string $from): void
    {
        $this->run = new Run(
            scope: $event->scope,
            what: $this->scopes[$event->scope] ?? '#' . $event->scope,
            at: $event->at,
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
            $dispatcher->dispatch(new Blocked(
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
