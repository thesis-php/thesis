<?php

declare(strict_types=1);

namespace Thesis\Async\Trace;

use Thesis\Async\Instant;

/**
 * A task started. A task cancelled before it starts emits only the end event.
 *
 * @api
 */
final readonly class TaskStarted implements Event
{
    /**
     * @param positive-int $scope
     */
    public function __construct(
        public int $scope,
        public Instant $at,
    ) {}
}
