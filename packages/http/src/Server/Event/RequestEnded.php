<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Event;

use Thesis\Http\Request;
use Time\Duration;

/**
 * @api
 */
final readonly class RequestEnded
{
    public function __construct(
        public Request $request,
        public ?int $status,
        public bool $responseWritten,
        public Duration $duration,
    ) {}
}
