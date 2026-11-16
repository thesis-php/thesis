<?php

declare(strict_types=1);

namespace Thesis\TraceRecorder;

/**
 * A task with the name its row has in the trace: `$group->spawn(Recorder::label('fetch A', $fetch))`.
 *
 * @template T
 */
final readonly class Labelled
{
    /**
     * @param non-empty-string $label
     * @param \Closure(): T $task
     */
    public function __construct(
        public string $label,
        private \Closure $task,
    ) {}

    /**
     * @return T
     */
    public function __invoke(): mixed
    {
        return ($this->task)();
    }
}
