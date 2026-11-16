<?php

declare(strict_types=1);

namespace Thesis\Async\Trace;

use Thesis\Async\Internal\Tracer;
use Thesis\Dispatcher;

/**
 * Dispatches the events of the whole scope tree to $dispatcher while $fn runs, of every fiber: run it in {main}
 * around the program, `VirtualTime\run()` inside for a trace that is the same on every run.
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
 * Without an observation, tracing costs one check of a static property in each wait.
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
