<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Event;

/**
 * @api
 */
final readonly class RequestHeadRejected
{
    public function __construct(
        public int $status,
        public string $reason,
    ) {}
}
