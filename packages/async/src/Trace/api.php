<?php

declare(strict_types=1);

namespace Thesis\Async\Trace;

use Thesis\Async\Internal\Tracer;

/**
 * Gives $observer the events of the whole scope tree while $fn runs, of every fiber: run it in {main}
 * around the program, `VirtualTime\run()` inside for a trace that is the same on every run.
 * Observations nest: each observer gets the events until its own call returns.
 *
 * Without an observer, tracing costs one check of a static property in each wait.
 *
 * @api
 * @template T
 * @param callable(): T $fn
 * @return T
 */
function observe(Observer $observer, callable $fn): mixed
{
    $tracer = Tracer::attach($observer);

    try {
        return $fn();
    } finally {
        $tracer->detach($observer);
    }
}
