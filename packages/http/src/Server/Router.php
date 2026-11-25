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
     * @var array<string, RouteNode> literal prefixes ending in an ordinary parameter
     */
    private array $trailing = [];

    /**
     * @param iterable<Route> $routes
     * @throws \InvalidArgumentException for duplicate routes, invalid patterns or methods
     */
    public function __construct(
        iterable $routes,
        private readonly bool $ignoreTrailingSlash = false,
    ) {
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
        if ($this->ignoreTrailingSlash) {
            $path = self::trimSlashes($path);
        }
        if (isset($this->exact[$path])) {
            $routes = $this->exact[$path];

            return $routes[$request->method] ?? implode(', ', array_keys($routes));
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
        $pattern = $route->route;
        if (!str_starts_with($pattern, '/') || str_contains($pattern, "\0")) {
            throw new \InvalidArgumentException("Invalid route pattern '{$pattern}'.");
        }
        foreach ($route->methods as $method) {
            if (!isToken($method)) {
                throw new \InvalidArgumentException("Invalid HTTP method '{$method}'.");
            }
        }

        $path = $this->ignoreTrailingSlash ? self::trimSlashes($pattern) : $pattern;
        if (strpbrk($path, '{}') === false && $route->requirements === [] && $route->defaults === []) {
            foreach ($route->methods as $method) {
                if (isset($this->exact[$path][$method])) {
                    throw new \InvalidArgumentException("Duplicate route {$method} {$pattern}.");
                }
                $this->exact[$path][$method] = [$route, new RouteMatch($method, $pattern)];
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
                $requirements[] = isset($route->requirements[$name])
                    ? self::expression($route->requirements[$name], $pattern)
                    : null;
                $end = $start + \strlen($token);
            }
            $literals[] = self::literal(substr($segment, $end), $segment, $pattern);
            if ($optional && $modifier !== '?') {
                throw new \InvalidArgumentException("Optional parameters must be trailing in '{$pattern}'.");
            }
            if ($modifier === '?') {
                $optional = true;
                ($node->optional ??= new RouteNode())->register($route, $before);
            }
            if ($tokens !== []) {
                $node->literalOnly = false;
            }
            if ($tokens === []) {
                $node = $node->literals[$segment] ??= new RouteNode();
            } elseif ($modifier === '+' || $modifier === '*') {
                if ($index !== array_key_last($segments)) {
                    throw new \InvalidArgumentException("Invalid remainder in '{$pattern}'.");
                }
                if (isset($requirements[0])) {
                    $regex = $requirements[0];
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
                $node->expressions[$key] ??= [new RouteSegment($literals, $requirements), new RouteNode()];
                $node = $node->expressions[$key][1];
            }
        }
        if (array_diff(array_keys($route->requirements + $route->defaults), $names) !== []) {
            throw new \InvalidArgumentException("Unknown parameter in '{$pattern}'.");
        }
        $node->register($route, $names);
    }

    private static function trimSlashes(string $path): string
    {
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    private static function expression(string $regex, string $pattern): string
    {
        $regex = "\x01\\A(?:{$regex})\\z\x01sD";
        if (@preg_match($regex, '') === false) {
            throw new \InvalidArgumentException("Invalid requirement in '{$pattern}'.");
        }

        return $regex;
    }

    private static function literal(string $literal, string $segment, string $pattern): string
    {
        if (strpbrk($literal, '{}') !== false) {
            throw new \InvalidArgumentException("Invalid parameter '{$segment}' in '{$pattern}'.");
        }

        return $literal;
    }
}
