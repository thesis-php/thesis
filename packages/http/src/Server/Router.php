<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Thesis\Http\Internal\Server\RouteTable;
use Thesis\Http\Internal\Server\RoutingError;
use Thesis\Http\Method;
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
        $path = $request->uri->getRawPath();
        if (
            $path === ''
            && $request->uri->getScheme() !== null
            && $request->method !== Method::CONNECT
            && ($request->method !== Method::OPTIONS || $request->uri->getRawQuery() !== null)
        ) {
            $path = '/';
        }

        $result = $this->routes->lookup($request->method, $path);

        if ($result instanceof RoutingError) {
            $response = $context->handleError($result->status, $request);

            return $result->allow === [] ? $response : $response->withHeader('Allow', implode(', ', $result->allow));
        }

        return $result[0]($request, $context, $result[1]);
    }
}
