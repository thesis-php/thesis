<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

/**
 * The key of the current fiber in the per-fiber maps: {@see CURRENT_SCOPE}, {@see CONTEXT}
 * and the locks of `Thesis\Async\synchronized()`. {main} has no \Fiber, so it gets an object of its own.
 *
 * NEVER store the result in a variable: call fiberKey() again every time the key is needed, in `finally` too.
 * The key is the fiber itself. Kept in a local variable while the fiber is suspended, it is a reference
 * from the fiber's own stack to the fiber: a cycle, so the fiber outlives its last reference, with its scope,
 * its values and its locks, until the cycle collector runs. The tests `valueGoesAwayWithFiber`,
 * `fiberSuspendedInRunGoesAwayWithLastReference` and `fiberSuspendedInsideGoesAwayWithLastReference` catch it.
 *
 * @internal
 */
function fiberKey(): object
{
    /** @var \stdClass */
    static $main = new \stdClass();

    return \Fiber::getCurrent() ?? $main;
}
