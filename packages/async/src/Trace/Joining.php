<?php

declare(strict_types=1);

namespace Thesis\Async\Trace;

use Thesis\Async\Instant;

/**
 * The body of a group has ended, and it waits for its tasks.
 *
 * @api
 */
final readonly class Joining implements Event
{
    /**
     * @param positive-int $scope
     */
    public function __construct(
        public int $scope,
        public Instant $at,
    ) {}
}
