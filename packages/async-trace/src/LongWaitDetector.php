<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace;

use Thesis\Async\Instant;
use Thesis\Async\Trace\Ended;
use Thesis\Async\Trace\GroupCreated;
use Thesis\Async\Trace\ObserverDetached;
use Thesis\Async\Trace\Resumed;
use Thesis\Async\Trace\ShieldCreated;
use Thesis\Async\Trace\Suspended;
use Thesis\Async\Trace\TaskCreated;
use Thesis\Async\Trace\TimeoutCreated;
use Thesis\AsyncTrace\Internal\Describe;
use Thesis\AsyncTrace\Internal\Wait;
use Thesis\Dispatcher;
use Thesis\Dispatcher\Subscriber;
use Time\Duration;
use function Thesis\Async\Trace\watchdog;
use function Thesis\Time\ns;
use function Thesis\Time\toNs;

/**
 * A watchdog of long waits: code of a scope that waits $threshold (a response that never comes,
 * a channel nobody sends to, a lock nobody releases) gets a {@see LongWait} in the dispatcher of the trace
 * while it still waits, once per wait. Give it to the dispatcher of the trace: `new Dispatcher([$detector, $listener])`.
 *
 * A hang brings no event, so it reports from a timer of the event loop, outside the scope tree: unreferenced,
 * it never keeps the program alive, and it is virtual under `virtualTime()`, like every timer of the loop.
 * A timer per wait, in the loop of that wait: a nested loop (`virtualTime()` in a task) runs on a clock of its own
 * and blocks the outer one. It is there only while the wait is not reported yet. Not seen: the waits of the root scope,
 * which have no events, and a hang of the whole loop (blocking code, see {@see BlockingDetector}).
 *
 * It listens to the waits, so each costs a backtrace: a tool of debugging, not of every request in production.
 *
 * @api
 */
final class LongWaitDetector implements Subscriber
{
    private readonly int $threshold;

    /**
     * The descriptions of the live scopes.
     *
     * @var array<positive-int, non-empty-string>
     */
    private array $scopes = [];

    /**
     * The waits not reported yet: what cancels the timer of each, by scope.
     *
     * @var \WeakMap<Dispatcher, \ArrayObject<positive-int, \Closure(): void>>
     */
    private readonly \WeakMap $timers;

    /**
     * @param Duration $threshold a wait that lasts so long is reported: one that ends exactly then may be or not,
     *     as the order of the callbacks of one tick decides
     */
    public function __construct(Duration $threshold)
    {
        $this->threshold = toNs($threshold);
        $this->timers = new \WeakMap();
    }

    #[\Override]
    public function listeners(): iterable
    {
        yield $this->onGroupCreated(...);
        yield $this->onTaskCreated(...);
        yield $this->onTimeoutCreated(...);
        yield $this->onShieldCreated(...);
        yield $this->onSuspended(...);
        yield $this->onResumed(...);
        yield $this->onEnded(...);
        yield $this->onDetached(...);
    }

    private function onGroupCreated(GroupCreated $event): void
    {
        $this->scopes[$event->scope] = Describe::scope($event);
    }

    private function onTaskCreated(TaskCreated $event): void
    {
        $this->scopes[$event->scope] = Describe::scope($event);
    }

    private function onTimeoutCreated(TimeoutCreated $event): void
    {
        $this->scopes[$event->scope] = Describe::scope($event);
    }

    private function onShieldCreated(ShieldCreated $event): void
    {
        $this->scopes[$event->scope] = Describe::scope($event);
    }

    private function onSuspended(Suspended $event, Dispatcher $dispatcher): void
    {
        $timers = $this->timers[$dispatcher] ??= new \ArrayObject();

        if (isset($timers[$event->scope])) {
            return;
        }

        $wait = new Wait(
            scope: $event->scope,
            what: $this->scopes[$event->scope] ?? '#' . $event->scope,
            at: $event->at,
            wait: $event->caller === null ? 'a wait of unknown code' : (string) $event->caller,
        );
        // A listener before this one may have taken time: the wait may be overdue already.
        $left = max(0, $this->threshold - toNs(Instant::now()->since($wait->at)));
        $timers[$wait->scope] = watchdog(ns($left), static function () use ($timers, $wait, $dispatcher) {
            unset($timers[$wait->scope]);
            $dispatcher->dispatch(new LongWait(
                scope: $wait->scope,
                what: $wait->what,
                at: $wait->at,
                duration: Instant::now()->since($wait->at),
                wait: $wait->wait,
            ));
        });
    }

    private function onResumed(Resumed $event, Dispatcher $dispatcher): void
    {
        $timers = $this->timers[$dispatcher] ?? null;
        $cancel = $timers[$event->scope] ?? null;

        if ($cancel !== null) {
            unset($timers[$event->scope]);
            $cancel();
        }
    }

    private function onEnded(Ended $event): void
    {
        // Its waits are over: `Resumed` comes before.
        unset($this->scopes[$event->scope]);
    }

    private function onDetached(ObserverDetached $event, Dispatcher $dispatcher): void
    {
        foreach ($this->timers[$dispatcher] ?? [] as $cancel) {
            $cancel();
        }

        unset($this->timers[$dispatcher]);
    }
}
