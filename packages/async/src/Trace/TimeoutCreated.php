<?php

declare(strict_types=1);

namespace Thesis\Async\Trace;

use Thesis\Async\Instant;
use Thesis\SourceLocation\Location;
use Time\Duration;

/**
 * A scope of `timeout()` or `timeoutOr()`.
 *
 * @api
 */
final readonly class TimeoutCreated implements Event
{
    /**
     * @param positive-int $scope
     * @param ?positive-int $parent null for the root scope
     * @param bool $cancelled whether it is created cancelled, by an ancestor or a deadline that has passed
     * @param ?Location $location where it is called from, outside the library
     */
    public function __construct(
        public int $scope,
        public Instant $at,
        public ?int $parent,
        public Duration $duration,
        public bool $cancelled,
        public ?Location $location,
    ) {}
}
