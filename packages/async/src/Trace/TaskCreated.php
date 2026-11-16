<?php

declare(strict_types=1);

namespace Thesis\Async\Trace;

use Thesis\Async\Instant;
use Thesis\SourceLocation\Location;

/**
 * A task or a daemon of a group, spawned, not yet started.
 *
 * @api
 */
final readonly class TaskCreated implements Event
{
    /**
     * @param positive-int $scope
     * @param positive-int $group
     * @param ?non-empty-string $name given to `Group::spawn()` or `Group::daemon()`, or the key of the task
     *        in `all()`, `allSettled()`, `some()` and `race()`; null for a worker of `each()` and `map()`
     * @param callable(): mixed $task what it runs
     * @param bool $cancelled whether it is created cancelled: it will never start
     * @param ?Location $location where it is spawned, outside the library
     */
    public function __construct(
        public int $scope,
        public Instant $at,
        public int $group,
        public bool $daemon,
        public ?string $name,
        public mixed $task,
        public bool $cancelled,
        public ?Location $location,
    ) {}
}
