<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace\Internal;

use Thesis\Async\Instant;
use Thesis\AsyncTrace\LongWaitDetector;

/**
 * A wait not reported yet, as {@see LongWaitDetector} keeps it.
 *
 * @internal
 */
final readonly class Wait
{
    /**
     * @param positive-int $scope
     * @param non-empty-string $what
     * @param non-empty-string $wait
     */
    public function __construct(
        public int $scope,
        public string $what,
        public Instant $at,
        public string $wait,
    ) {}
}
