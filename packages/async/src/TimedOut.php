<?php

declare(strict_types=1);

namespace Thesis\Async;

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
        public readonly float $seconds,
        Cancelled $previous,
    ) {
        parent::__construct(\sprintf('Timed out after %s seconds.', $seconds), previous: $previous);
    }
}
