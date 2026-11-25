<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Thesis\Http\Request;
use const Thesis\Http\Internal\ROUTE_MATCHES;

/**
 * The active route for this request, or null outside its handler.
 *
 * @api
 */
function routeMatch(Request $request): ?RouteMatch
{
    return ROUTE_MATCHES[$request] ?? null;
}
