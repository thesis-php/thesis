<?php

declare(strict_types=1);

namespace Thesis\Async\Trace;

use Thesis\Async\Instant;
use Thesis\SourceLocation\Caller;

/**
 * The code of a scope waits, in `suspend()`: every wait of the library is one.
 *
 * @api
 */
final readonly class Suspended implements Event
{
    /**
     * @param positive-int $scope
     * @param ?Caller $caller the call that waits, outside the library: `Thesis\Async\delay` and where it is written
     */
    public function __construct(
        public int $scope,
        public Instant $at,
        public ?Caller $caller,
    ) {}
}
