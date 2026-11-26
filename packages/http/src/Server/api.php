<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Thesis\Http\Request;
use const Thesis\Http\Server\Internal\ROUTE_MATCHES;

/**
 * The active route for this request, or null outside its handler.
 *
 * @api
 */
function routeMatch(Request $request): ?RouteMatch
{
    return ROUTE_MATCHES[$request] ?? null;
}

/**
 * Returns the named parameter, or null if the request has no active match or the parameter is absent.
 *
 * @api
 */
function routeParam(Request $request, string $name): ?string
{
    return routeMatch($request)?->param($name);
}
