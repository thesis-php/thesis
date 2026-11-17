<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace\Internal;

use Thesis\Async\Instant;
use Thesis\Async\Trace\CancelCause;
use Thesis\AsyncTrace\WhoCancelled;

/**
 * A cancellation as {@see WhoCancelled} keeps it: shared by every scope it has reached,
 * with its reason formatted at once, while the scope that has cancelled is still known.
 *
 * @internal
 */
final readonly class Cancellation
{
    /**
     * @param ?positive-int $by
     * @param non-empty-string $reason
     */
    public function __construct(
        public Instant $at,
        public CancelCause $cause,
        public ?int $by,
        public string $reason,
    ) {}
}
