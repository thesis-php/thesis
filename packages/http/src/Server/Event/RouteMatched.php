<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Event;

use Thesis\Http\Request;
use Thesis\Http\Server\RouteMatch;

/**
 * @api
 */
final readonly class RouteMatched
{
    public function __construct(
        public Request $request,
        public RouteMatch $match,
    ) {}
}
