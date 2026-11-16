<?php

declare(strict_types=1);

namespace Thesis\Async\Trace;

use Thesis\Async\Instant;

/**
 * The wait is over.
 *
 * @api
 */
final readonly class Resumed implements Event
{
    /**
     * @param positive-int $scope
     * @param ?\Throwable $error what the wait throws: `Cancelled` if a cancellation has ended it, null if a value
     */
    public function __construct(
        public int $scope,
        public Instant $at,
        public ?\Throwable $error,
    ) {}
}
