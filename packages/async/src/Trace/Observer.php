<?php

declare(strict_types=1);

namespace Thesis\Async\Trace;

/**
 * Gets the events of the scope tree while {@see observe()} runs: to trace, to debug, to measure.
 *
 * An observer only observes, the library does not guard against one that does more:
 * {@see handle()} must not throw, suspend the fiber or cancel anything, and must not keep the values
 * and the errors of the events (format them right away), or it would hold them alive past their scope.
 *
 * @api
 */
interface Observer
{
    public function handle(Event $event): void;
}
