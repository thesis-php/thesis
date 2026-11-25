<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Event;

use Thesis\Http\Request;

/**
 * @api
 */
final readonly class RequestStarted
{
    public function __construct(
        public Request $request,
    ) {}
}
