<?php

declare(strict_types=1);

namespace Thesis\Async\Trace;

use Thesis\Async\Internal\Callback;
use Thesis\Async\Internal\Loop;
use Thesis\Async\Internal\Scope;
use Thesis\Async\Internal\Tracer;
use Thesis\Dispatcher;
use Time\Duration;
use function Thesis\Time\toNs;

/**
 * Dispatches the events of the whole scope tree to $dispatcher while $fn runs, of every fiber: run it in {main}
 * around the program, `virtualTime()` inside for a trace that is the same on every run.
 * Observations nest and may overlap in different fibers: each dispatcher gets the events until its own call
 * returns, and a dispatcher passed to two of them gets each event twice. Overlapping observations share
 * the numbers of the scopes, which start from 1 only when none is running: one that begins while another
 * runs, or while tasks run, gets the events of scopes whose creation it has not seen. An event that no listener
 * gets is not built, so a listener of {@see ScopeCancelled} alone does not pay for the backtraces of the waits.
 *
 * A listener only observes, the library does not guard against one that does more: it must not throw,
 * suspend the fiber or cancel anything, and must not keep the values and the errors of the events
 * (format them right away), or it would hold them alive past their scope.
 *
 * A wait reads the active tracer before it suspends and again when it resumes.
 *
 * @api
 * @template T
 * @param callable(): T $fn
 * @return T
 */
function observe(Dispatcher $dispatcher, callable $fn): mixed
{
    $tracer = Tracer::attach($dispatcher);

    try {
        return $fn();
    } finally {
        $tracer->detach($dispatcher);
    }
}

/**
 * Registers cleanup for a tool that holds resources while observing. The last observe() with this
 * dispatcher calls the callback on exit. Outside an observation of this dispatcher nothing is registered.
 * The callback follows the rules of a listener: it must not throw or wait.
 *
 * @api
 * @param callable(): void $callback
 * @return \Closure(): void unregister, idempotent
 */
function onDetach(Dispatcher $dispatcher, callable $callback): \Closure
{
    return Scope::$tracer?->onDetach($dispatcher, $callback)
        ?? static function () {};
}

/**
 * Calls $callback once $delay has passed, outside the scope tree: for a tool that reports what does not happen
 * (a wait that lasts too long), which no event of a trace can tell. The timer does not keep the program alive,
 * and under `virtualTime()` it is virtual, like every timer of the event loop.
 *
 * $callback runs in the event loop between the fibers, in no scope: it must not wait, and a throw goes to the loop,
 * which throws it into the wait of the group that runs it in {main}.
 *
 * @api
 * @param callable(): void $callback
 * @return \Closure(): void cancel, idempotent: it is never called after this
 */
function watchdog(Duration $delay, callable $callback): \Closure
{
    $loop = Loop::get();
    $timer = $loop->delay(toNs($delay), new Callback($callback(...)), referenced: false);

    return static function () use ($loop, $timer) {
        $loop->cancel($timer);
    };
}
