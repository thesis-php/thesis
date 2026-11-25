<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Thesis\Http\Headers;
use Thesis\Http\Internal\Server\RouteTable;
use Thesis\Http\Internal\Server\RoutingError;
use Thesis\Http\Request;
use Thesis\Http\Response;

/**
 * @api
 */
final readonly class Router
{
    private RouteTable $routes;

    /**
     * @param iterable<Route> $routes
     * @throws \InvalidArgumentException for duplicate routes, invalid patterns or methods
     */
    public function __construct(iterable $routes)
    {
        $this->routes = new RouteTable($routes);
    }

    public function __invoke(Request $request, Context $context): Response
    {
        $result = $this->routes->lookup($request->method, $request->uri->getRawPath());

        if ($result instanceof RoutingError) {
            return new Response(
                status: $result->status,
                headers: new Headers($result->allow === [] ? [] : ['Allow' => implode(', ', $result->allow)]),
            );
        }

        return $result[0]($request, $context, $result[1]);
    }
}
