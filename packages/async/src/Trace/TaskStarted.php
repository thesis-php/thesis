<?php

declare(strict_types=1);

namespace Thesis\Async\Trace;

use Thesis\Async\Instant;

/**
 * A task has started: one cancelled before it never starts and only ends.
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
