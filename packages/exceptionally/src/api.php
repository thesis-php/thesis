<?php

declare(strict_types=1);

namespace Thesis;

use Thesis\Exceptionally\Internal\ErrorHandler;

/**
 * Calls the function and throws its errors at the levels as \ErrorException, and its false too.
 * Other errors (suppressed, at other levels, of other fibers) go to the previous handler.
 * The function may suspend the fiber.
 *
 * @api
 * @template T
 * @param callable(): T $fn
 * @return (T is false ? never : T)
 * @throws \ErrorException
 */
function exceptionally(callable $fn, int $levels = E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED): mixed
{
    // Per fiber: while a call is suspended, other fibers run under its handler
    /** @var \WeakMap<object, int> $levelsOfFibers */
    static $levelsOfFibers = new \WeakMap();
    // {main} has no \Fiber, so it gets a key of its own
    /** @var \stdClass */
    static $main = new \stdClass();

    // NEVER keep the key in a variable: kept while the fiber is suspended in $fn(), it is a reference
    // from the stack of the fiber to the fiber itself, a cycle that outlives the last reference to the fiber
    $outerLevels = $levelsOfFibers[\Fiber::getCurrent() ?? $main] ?? null;
    $levelsOfFibers[\Fiber::getCurrent() ?? $main] = $levels;

    $handler = get_error_handler();

    if (!$handler instanceof ErrorHandler) {
        $handler = new ErrorHandler(previous: $handler, levelsOfFibers: $levelsOfFibers, main: $main);
    }

    set_error_handler($handler);

    try {
        $result = $fn();
    } finally {
        // The top may be a handler of other code or none (a handler runs): only ours of finished calls go
        ++$handler->finishedCalls;

        // NOTHING may come between reading the number and restore_error_handler(): see AGENTS.md
        while (($top = get_error_handler()) instanceof ErrorHandler && $top->finishedCalls > 0) {
            restore_error_handler();
            --$top->finishedCalls;
        }

        if ($outerLevels === null) {
            unset($levelsOfFibers[\Fiber::getCurrent() ?? $main]);
        } else {
            $levelsOfFibers[\Fiber::getCurrent() ?? $main] = $outerLevels;
        }
    }

    if ($result === false) {
        // E_WARNING: it stands for the warning that a function of PHP fails with
        throw new \ErrorException('The function returned false and no error was thrown', severity: E_WARNING);
    }

    return $result;
}
