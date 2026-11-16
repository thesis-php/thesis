<?php

declare(strict_types=1);

namespace Thesis\Async\Trace;

use Thesis\Async\Instant;
use Thesis\SourceLocation\Location;

/**
 * A group: `group()`, `all()`, `race()` and the others that run on one.
 *
 * @api
 */
final readonly class GroupCreated implements Event
{
    /**
     * @param positive-int $scope
     * @param ?positive-int $parent null for the root scope
     * @param bool $cancelled whether it is created cancelled, by an ancestor
     * @param ?Location $location where it is called from, outside the library
     */
    public function __construct(
        public int $scope,
        public Instant $at,
        public ?int $parent,
        public bool $cancelled,
        public ?Location $location,
    ) {}
}
