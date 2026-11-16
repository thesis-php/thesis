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
     * @param non-empty-string $function the function that has created it: `Thesis\Async\group`, `Thesis\Async\race`, ...
     * @param ?non-empty-string $name given to `group()`; null for one of `all()`, `race()` and the others
     * @param bool $cancelled whether it is created cancelled, by an ancestor
     * @param ?Location $location where it is called from, outside the library
     */
    public function __construct(
        public int $scope,
        public Instant $at,
        public ?int $parent,
        public string $function,
        public ?string $name,
        public bool $cancelled,
        public ?Location $location,
    ) {}
}
