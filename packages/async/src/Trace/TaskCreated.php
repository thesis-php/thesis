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
     * @param callable(): mixed $task what it runs, to name it in a trace
     * @param bool $cancelled whether it is created cancelled: it will never start
     * @param ?Location $location where it is spawned, outside the library
     */
    public function __construct(
        public int $scope,
        public Instant $at,
        public int $group,
        public bool $daemon,
        public mixed $task,
        public bool $cancelled,
        public ?Location $location,
    ) {}
}
