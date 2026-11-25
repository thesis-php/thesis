<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Thesis\Http\Internal\Server\RouteNode;
use Thesis\Http\Internal\Server\RouteSegment;
use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Status;
use function Thesis\Http\Internal\isToken;
use const Thesis\Http\Internal\ROUTE_MATCHES;

/**
 * @api
 */
final class Router
{
    private readonly RouteNode $root;

    /**
     * @var array<string, array<array-key, array{Route, RouteMatch}>>
     */
    private array $exact = [];

    /**
     * @var array<string, Route>
     */
    private array $exactFallback = [];

    /**
     * @var array<string, RouteNode> literal prefixes ending in an ordinary parameter
     */
    private array $trailing = [];

    /**
     * @param iterable<Route> $routes
     * @throws \InvalidArgumentException for duplicate routes, invalid patterns or methods
     */
    public function __construct(iterable $routes)
    {
        $this->root = new RouteNode();
        foreach ($routes as $route) {
            $this->register($route);
        }
        foreach ($this->exact as &$methods) {
            if (isset($methods[Method::GET])) {
                $methods[Method::HEAD] ??= $methods[Method::GET];
            }
        }
        foreach ($this->trailing as $prefix => $parent) {
            if ($parent->literals !== [] || $parent->expressions !== [] || $parent->parameter === null) {
                unset($this->trailing[$prefix]);
            } else {
                $this->trailing[$prefix] = $parent->parameter;
            }
        }
    }

    public function lookup(Request $request): ?RouteMatch
    {
        $result = $this->resolve($request);

        return \is_array($result) ? $result[1] : null;
    }

    public function __invoke(Request $request, Context $context): Response
    {
        $result = $this->resolve($request);
        if (\is_string($result)) {
            $response = $context->errorHandler->handleError(Status::METHOD_NOT_ALLOWED, $request);

            return $response->withHeader('Allow', $result);
        }
        if (\is_int($result)) {
            return $context->errorHandler->handleError($result, $request);
        }
        [$route, $match] = $result;
        $matches = ROUTE_MATCHES;
        $previous = $matches[$request] ?? null;
        $matches[$request] = $match;

        try {
            if ($context->dispatcher->listens(Event\RouteMatched::class)) {
                $context->dispatcher->dispatch(new Event\RouteMatched(
                    request: $request,
                    match: $match,
                ));
            }

            return ($route->handler)($request, $context);
        } finally {
            if ($previous === null) {
                unset($matches[$request]);
            } else {
                $matches[$request] = $previous;
            }
        }
    }

    /**
     * @return array{Route, RouteMatch}|string|400|404
     */
    private function resolve(Request $request): array|string|int
    {
        $path = $request->uri->getRawPath();
        if ($path === '') {
            if (
                $request->uri->getScheme() === null
                || $request->method === Method::CONNECT
                || ($request->method === Method::OPTIONS && $request->uri->getRawQuery() === null)
            ) {
                return Status::NOT_FOUND;
            }
            $path = '/';
        }

        if (str_contains($path, '%')) {
            if (preg_match('/%(?:2f|00|(?![a-f0-9]{2}))/i', $path) === 1) {
                return Status::BAD_REQUEST;
            }
            $path = rawurldecode($path);
        }
        if (str_contains($path, "\0")) {
            return Status::BAD_REQUEST;
        }
        if (isset($this->exact[$path])) {
            $routes = $this->exact[$path];

            if (isset($routes[$request->method])) {
                return $routes[$request->method];
            }
            if (isset($this->exactFallback[$path])) {
                $route = $this->exactFallback[$path];

                return [$route, new RouteMatch($request->method, $route->pattern)];
            }

            return implode(', ', array_keys($routes));
        }
        if ($path[0] !== '/') {
            return Status::NOT_FOUND;
        }
        if ($this->trailing !== []) {
            $slash = strrpos($path, '/');
            if ($slash !== false) {
                $node = $this->trailing[substr($path, 0, $slash + 1)] ?? null;
                if ($node !== null && $slash + 1 < \strlen($path)) {
                    return $node->select([substr($path, $slash + 1)], $request->method);
                }
            }
        }

        if ($path === '/') {
            return ($this->root->literals[''] ?? null)?->find([], 0, [], $request->method)
                ?? $this->root->find([], 0, [], $request->method)
                ?? Status::NOT_FOUND;
        }

        return $this->root->find(explode('/', $path), 1, [], $request->method) ?? Status::NOT_FOUND;
    }

    private function register(Route $route): void
    {
        $pattern = $route->pattern;
        if (!str_starts_with($pattern, '/') || str_contains($pattern, "\0")) {
            throw new \InvalidArgumentException("Invalid route pattern '{$pattern}'.");
        }
        foreach ($route->methods ?? [] as $method) {
            if (!isToken($method)) {
                throw new \InvalidArgumentException("Invalid HTTP method '{$method}'.");
            }
        }

        $optionalSlash = str_ends_with($pattern, '[/]');
        $path = $optionalSlash ? substr($pattern, 0, -3) : $pattern;
        if (strpbrk($path, '{}') === false && $route->requirements === [] && $route->defaults === []) {
            foreach ($optionalSlash ? [$path, $path . '/'] : [$path] as $literal) {
                if ($route->methods === null) {
                    if (isset($this->exactFallback[$literal])) {
                        throw new \InvalidArgumentException("Duplicate fallback route {$pattern}.");
                    }
                    $this->exact[$literal] ??= [];
                    $this->exactFallback[$literal] = $route;

                    continue;
                }
                foreach ($route->methods as $method) {
                    if (isset($this->exact[$literal][$method])) {
                        throw new \InvalidArgumentException("Duplicate route {$method} {$pattern}.");
                    }
                    $this->exact[$literal][$method] = [$route, new RouteMatch($method, $pattern)];
                }
            }

            return;
        }
        $segments = $path === '/' ? [] : explode('/', substr($path, 1));
        $node = $this->root;
        $names = [];
        $optional = false;
        foreach ($segments as $index => $segment) {
            preg_match_all(
                '/\{([A-Za-z_][A-Za-z_0-9]*)([?+*]?)\}/',
                $segment,
                $tokens,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            );
            $literals = [];
            $requirements = [];
            $end = 0;
            $modifier = '';
            $before = $names;
            foreach ($tokens as [[$token, $start], [$name], [$suffix]]) {
                $modifier = $suffix;
                if (\in_array($name, $names, true) || ($modifier !== '' && $segment !== $token)) {
                    throw new \InvalidArgumentException("Invalid parameter '{$segment}' in '{$pattern}'.");
                }
                $names[] = $name;
                $literal = substr($segment, $end, $start - $end);
                $literals[] = self::literal($literal, $segment, $pattern);
                $requirements[] = $route->requirements[$name] ?? null;
                $end = $start + \strlen($token);
            }
            $literals[] = self::literal(substr($segment, $end), $segment, $pattern);
            if ($optional && $modifier !== '?') {
                throw new \InvalidArgumentException("Optional parameters must be trailing in '{$pattern}'.");
            }
            if ($modifier === '?') {
                $optional = true;
                if ($optionalSlash) {
                    $slash = $node->literals[''] ??= new RouteNode();
                    ($slash->optional ??= new RouteNode())->register($route, $before);
                }
                ($node->optional ??= new RouteNode())->register($route, $before);
            }
            if ($tokens !== []) {
                $node->literalOnly = false;
            }
            if ($tokens === []) {
                $node = $node->literals[$segment] ??= new RouteNode();
            } elseif ($modifier === '+' || $modifier === '*') {
                if ($optionalSlash || $index !== array_key_last($segments)) {
                    throw new \InvalidArgumentException("Invalid remainder in '{$pattern}'.");
                }
                if (isset($requirements[0])) {
                    $regex = RouteSegment::requirement($requirements[0], $pattern);
                    $node = $node->remainderExpressions[$regex] ??= new RouteNode();
                } else {
                    $node = $node->remainder ??= new RouteNode();
                }
                if ($modifier === '*') {
                    ($node->optional ??= new RouteNode())->register($route, $names);
                }
            } elseif ($segment === $tokens[0][0][0] && !isset($route->requirements[$tokens[0][1][0]])) {
                if ($before === [] && $index === array_key_last($segments)) {
                    $this->trailing[substr($path, 0, -\strlen($segment))] = $node;
                }
                $node = $node->parameter ??= new RouteNode();
            } else {
                $key = serialize([$literals, $requirements]);
                $node->expressions[$key] ??= [new RouteSegment($literals, $requirements, $pattern), new RouteNode()];
                $node = $node->expressions[$key][1];
            }
        }
        if (array_diff(array_keys($route->requirements + $route->defaults), $names) !== []) {
            throw new \InvalidArgumentException("Unknown parameter in '{$pattern}'.");
        }
        $node->register($route, $names);
        if ($optionalSlash) {
            ($node->literals[''] ??= new RouteNode())->register($route, $names);
        }
    }

    private static function literal(string $literal, string $segment, string $pattern): string
    {
        if (strpbrk($literal, '{}') !== false) {
            throw new \InvalidArgumentException("Invalid parameter '{$segment}' in '{$pattern}'.");
        }

        return $literal;
    }
}
