<?php

declare(strict_types=1);

namespace Thesis;

use Thesis\Internal\ErrorHandler;

/**
 * Calls the function and throws an error it triggers at one of the levels as an \ErrorException.
 * An error suppressed with @, at another level or of another fiber goes to the previous handler.
 * False is a failure: an \ErrorException is thrown when the function returns false and no error was thrown.
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
    // The handler stack is global, while a call of exceptionally() belongs to one fiber: while it is suspended,
    // other fibers run under its handler. So the handler reads the levels of the current fiber.
    /** @var \WeakMap<object, non-empty-list<int>> $levelsOfFibers */
    static $levelsOfFibers = new \WeakMap();
    // {main} has no \Fiber, so it gets a key of its own
    /** @var \stdClass */
    static $main = new \stdClass();

    // NEVER keep the key in a variable: kept while the fiber is suspended in $fn(), it is a reference
    // from the stack of the fiber to the fiber itself, a cycle that outlives the last reference to the fiber
    $levelsOfFibers[\Fiber::getCurrent() ?? $main] = [
        ...($levelsOfFibers[\Fiber::getCurrent() ?? $main] ?? []),
        $levels,
    ];

    $handler = get_error_handler();

    if (!$handler instanceof ErrorHandler) {
        $handler = new ErrorHandler(previous: $handler, levelsOfFibers: $levelsOfFibers, main: $main);
    }

    set_error_handler($handler);

    try {
        $result = $fn();
    } finally {
        // The top of the stack is not always the copy of this call: other code may have set its handler above
        // (it stays), or a handler runs (PHP has none then). So the call only says that it has finished,
        // and what is taken off is a handler of ours on top that a finished call has left.
        ++$handler->finishedCalls;

        // NOTHING may come between reading the number and restore_error_handler(): see AGENTS.md
        while (($top = get_error_handler()) instanceof ErrorHandler && $top->finishedCalls > 0) {
            restore_error_handler();
            --$top->finishedCalls;
        }

        $stack = $levelsOfFibers[\Fiber::getCurrent() ?? $main];
        array_pop($stack);

        if ($stack === []) {
            unset($levelsOfFibers[\Fiber::getCurrent() ?? $main]);
        } else {
            $levelsOfFibers[\Fiber::getCurrent() ?? $main] = $stack;
        }
    }

    if ($result === false) {
        // E_WARNING: the exception stands for the warning that a function of PHP fails with
        throw new \ErrorException('The function returned false and no error was thrown', severity: E_WARNING);
    }

    return $result;
}
