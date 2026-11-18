<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace;

use Thesis\Async\Instant;
use Thesis\Async\Trace\Ended;
use Thesis\Async\Trace\GroupCreated;
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
 * The timer is there only while a wait is not reported yet. Not seen: the waits of the root scope,
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
     * The waits not reported yet, in the order they have begun: the first one is due first.
     *
     * @var array<positive-int, Wait>
     */
    private array $waits = [];

    /**
     * @var ?\Closure(): void cancels the timer
     */
    private ?\Closure $timer = null;

    /**
     * @param Duration $threshold a wait that lasts so long is reported: one that ends exactly then may be or not,
     *     as the order of the callbacks of one tick decides
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
        yield $this->onSuspended(...);
        yield $this->onResumed(...);
        yield $this->onEnded(...);
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
        $wait = new Wait(
            scope: $event->scope,
            what: $this->scopes[$event->scope] ?? '#' . $event->scope,
            at: $event->at,
            wait: $event->caller === null ? 'a wait of unknown code' : (string) $event->caller,
        );
        $this->waits[$event->scope] = $wait;
        $this->timer ??= $this->arm($wait, $dispatcher);
    }

    private function onResumed(Resumed $event): void
    {
        unset($this->waits[$event->scope]);
        $this->disarmIfNothingWaits();
    }

    private function onEnded(Ended $event): void
    {
        // Its waits are over: `Resumed` comes before.
        unset($this->scopes[$event->scope]);
    }

    /**
     * @return \Closure(): void
     */
    private function arm(Wait $wait, Dispatcher $dispatcher): \Closure
    {
        $left = max(0, $this->threshold - toNs(Instant::now()->since($wait->at)));

        return watchdog(ns($left), function () use ($dispatcher) {
            $this->report($dispatcher);
        });
    }

    /**
     * A timer of the real clock may wake a hair early: a wait not due yet waits for the next one.
     */
    private function report(Dispatcher $dispatcher): void
    {
        $this->timer = null;
        $now = Instant::now();

        foreach ($this->waits as $scope => $wait) {
            $waited = toNs($now->since($wait->at));

            if ($waited < $this->threshold) {
                $this->timer = $this->arm($wait, $dispatcher);

                return;
            }

            unset($this->waits[$scope]);
            $dispatcher->dispatch(new LongWait(
                scope: $wait->scope,
                what: $wait->what,
                at: $wait->at,
                duration: ns($waited),
                wait: $wait->wait,
            ));
        }
    }

    private function disarmIfNothingWaits(): void
    {
        if ($this->waits === [] && $this->timer !== null) {
            ($this->timer)();
            $this->timer = null;
        }
    }
}
