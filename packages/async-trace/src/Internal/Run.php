<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace\Internal;

use Thesis\Async\Instant;
use Thesis\AsyncTrace\BlockingDetector;

/**
 * The code that runs now without giving up control, as {@see BlockingDetector} keeps it.
 *
 * @internal
 */
final readonly class Run
{
    /**
     * @param positive-int $scope
     * @param non-empty-string $what
     * @param non-empty-string $from
     */
    public function __construct(
        public int $scope,
        public string $what,
        public Instant $at,
        public string $from,
    ) {}
}
