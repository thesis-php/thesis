<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * @api
 */
final class TimedOut extends \RuntimeException
{
    /**
     * @internal
     */
    public function __construct(
        public readonly float $seconds,
    ) {
        parent::__construct(\sprintf('Timed out after %s seconds.', $seconds));
    }
}
