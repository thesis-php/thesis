<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace\Internal;

use Thesis\Async\Instant;
use Thesis\Async\Trace\CancelCause;
use Thesis\AsyncTrace\CancellationTracker;

/**
 * The cause of a cancellation as {@see CancellationTracker} keeps it: shared by every scope it has reached,
 * with its reason formatted at once, while the scope that has cancelled is still known.
 *
 * @internal
 */
final readonly class Cause
{
    /**
     * @param ?positive-int $by
     * @param non-empty-string $reason
     */
    public function __construct(
        public Instant $at,
        public CancelCause $kind,
        public ?int $by,
        public string $reason,
    ) {}
}
