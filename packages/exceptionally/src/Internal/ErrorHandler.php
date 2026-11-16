<?php

declare(strict_types=1);

namespace Thesis\Internal;

/**
 * The error handler that `Thesis\exceptionally()` sets.
 *
 * While it is on top of the handler stack, every call sets the same object again, so its copies lie next to each
 * other and are equal: a call that finishes takes any of them off. A new object is made only above a handler
 * of other code (or none), which becomes its previous one.
 *
 * @internal
 */
final class ErrorHandler
{
    /**
     * The number of finished calls whose copy of the handler is still on the stack.
     */
    public int $finishedCalls = 0;

    /**
     * @param ?callable(int, string, string, int): mixed $previous
     * @param \WeakMap<object, non-empty-list<int>> $levelsOfFibers
     */
    public function __construct(
        private readonly mixed $previous,
        private readonly \WeakMap $levelsOfFibers,
        private readonly \stdClass $main,
    ) {}

    /**
     * @throws \ErrorException
     */
    public function __invoke(int $level, string $message, string $file, int $line): bool
    {
        // The stack is global, so the error may be of any fiber, not of the one that set this copy
        $stack = $this->levelsOfFibers[\Fiber::getCurrent() ?? $this->main] ?? [];
        $levels = array_last($stack) ?? 0;
        // error_reporting() is a part of the fiber state: @ in another fiber does not change it here
        $reported = (error_reporting() & $level) !== 0;

        if ($reported && ($levels & $level) !== 0) {
            throw new \ErrorException($message, severity: $level, filename: $file, line: $line);
        }

        return $this->previous !== null && ($this->previous)($level, $message, $file, $line) !== false;
    }
}
