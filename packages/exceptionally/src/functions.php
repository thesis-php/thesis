<?php

declare(strict_types=1);

namespace Thesis;

/**
 * Calls the function and throws an error it triggers at one of the levels as an \ErrorException.
 * An error suppressed with @, at another level or of another fiber goes to the previous handler.
 * The function may suspend the fiber.
 *
 * @api
 * @template T
 * @param callable(): T $function
 * @return T
 * @throws \ErrorException
 */
function exceptionally(callable $function, int $levels = E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED): mixed
{
    // The handler stack is global, while a call of exceptionally() belongs to one fiber: while it is suspended,
    // other fibers run under its handler and may restore it out of order. So every handler reads the levels
    // of the current fiber, and it does not matter which of them is on top.
    /** @var \WeakMap<object, non-empty-list<int>> $levelsOfFibers */
    static $levelsOfFibers = new \WeakMap();
    // {main} has no \Fiber, so it gets a key of its own
    /** @var \stdClass */
    static $main = new \stdClass();

    // NEVER keep the key in a variable: kept while the fiber is suspended in $function(), it is a reference
    // from the stack of the fiber to the fiber itself, a cycle that outlives the last reference to the fiber
    $stack = $levelsOfFibers[\Fiber::getCurrent() ?? $main] ?? [];
    $stack[] = $levels;
    $levelsOfFibers[\Fiber::getCurrent() ?? $main] = $stack;

    $previous = null;
    $previous = set_error_handler(
        static function (int $level, string $message, string $file, int $line) use (
            &$previous,
            $levelsOfFibers,
            $main,
        ) {
            $stack = $levelsOfFibers[\Fiber::getCurrent() ?? $main] ?? [];
            $levels = array_last($stack) ?? 0;
            // error_reporting() is a part of the fiber state: @ in another fiber does not change it here
            $reported = (error_reporting() & $level) !== 0;

            if ($reported && ($levels & $level) !== 0) {
                throw new \ErrorException($message, severity: $level, filename: $file, line: $line);
            }

            return $previous !== null && $previous($level, $message, $file, $line) !== false;
        },
    );

    try {
        return $function();
    } finally {
        restore_error_handler();

        $stack = $levelsOfFibers[\Fiber::getCurrent() ?? $main];
        array_pop($stack);

        if ($stack === []) {
            unset($levelsOfFibers[\Fiber::getCurrent() ?? $main]);
        } else {
            $levelsOfFibers[\Fiber::getCurrent() ?? $main] = $stack;
        }
    }
}
