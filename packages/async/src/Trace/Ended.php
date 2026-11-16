<?php

declare(strict_types=1);

namespace Thesis\Async\Trace;

use Thesis\Async\Instant;

/**
 * The code of a scope has ended, with its value or its error. For a group, after its tasks.
 *
 * @api
 */
final readonly class Ended implements Event
{
    /**
     * @param positive-int $scope
     * @param mixed $value null if it has thrown
     * @param ?\Throwable $error what it throws: `Cancelled` for a cancelled task, as its handle throws `TaskCancelled`
     */
    public function __construct(
        public int $scope,
        public Instant $at,
        public mixed $value,
        public ?\Throwable $error,
    ) {}
}
