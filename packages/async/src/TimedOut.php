<?php

declare(strict_types=1);

namespace Thesis\Async;

use Time\Duration;
use function Thesis\Time\formatDuration;

/**
 * Thrown by {@see timeout()} when its own deadline, not an outer cancellation, interrupted the body.
 *
 * @api
 */
final class TimedOut extends \RuntimeException
{
    /**
     * @internal
     */
    public function __construct(
        public readonly Duration $timeout,
        Cancelled $previous,
    ) {
        parent::__construct(\sprintf('Timed out after %s.', formatDuration($timeout)), previous: $previous);
    }
}
