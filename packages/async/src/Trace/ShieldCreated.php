<?php

declare(strict_types=1);

namespace Thesis\Async\Trace;

use Thesis\Async\Instant;
use Thesis\SourceLocation\Location;

/**
 * `shield()` created a scope.
 *
 * @api
 */
final readonly class ShieldCreated implements Event
{
    /**
     * @param positive-int $scope
     * @param ?positive-int $parent null for the root scope
     * @param bool $cancelled always false: a shield hides the cancellation of its ancestors
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
