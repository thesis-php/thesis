<?php

declare(strict_types=1);

namespace Thesis\Exceptionally\Internal;

/**
 * The handler of `Thesis\exceptionally()`: one object per run of our copies on the stack, see AGENTS.md.
 *
 * @internal
 */
final class ErrorHandler
{
    /**
     * Finished calls whose copy is still on the stack.
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
        // The error may be of any fiber, not of the one that set this copy
        $stack = $this->levelsOfFibers[\Fiber::getCurrent() ?? $this->main] ?? [];
        $levels = array_last($stack) ?? 0;
        // Per fiber: @ in another fiber does not change it
        $reported = (error_reporting() & $level) !== 0;

        if ($reported && ($levels & $level) !== 0) {
            throw new \ErrorException($message, severity: $level, filename: $file, line: $line);
        }

        return $this->previous !== null && ($this->previous)($level, $message, $file, $line) !== false;
    }
}
