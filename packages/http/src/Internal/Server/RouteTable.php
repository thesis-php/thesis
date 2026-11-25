<?php

declare(strict_types=1);

namespace Thesis\Http\Internal\Server;

use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Server\Context;
use Thesis\Http\Server\Route;
use Thesis\Http\Server\RouteMatch;

/**
 * @internal
 * @phpstan-type Dispatch array{\Closure(Request, Context, RouteMatch): Response, RouteMatch}
 */
final class RouteTable
{
    /**
     * @var array<string, array<array-key, Dispatch>>
     */
    private array $exact = [];

    /**
     * @var array<string, array<array-key, array{Route, non-empty-string}>>
     */
    private array $trailing = [];

    /**
     * Paths of equal length and parameter positions share a hash table.
     * @var array<int, array<array-key, array{mask: array<int, string>, paths: array<string,
     *     array<array-key, array{Route, array<int, non-empty-string>}>>}>>
     */
    private array $patterns = [];

    /**
     * @param iterable<Route> $routes
     * @throws \InvalidArgumentException for duplicate routes, invalid patterns or methods
     */
    public function __construct(iterable $routes)
    {
        foreach ($routes as $route) {
            $this->register($route);
        }

        foreach ($this->patterns as &$patterns) {
            krsort($patterns, SORT_STRING);
        }
    }

    /**
     * The path is the raw path component without the query.
     * @return Dispatch|RoutingError
     */
    public function lookup(string $method, string $path): array|RoutingError
    {
        if (str_contains($path, '%')) {
            if (preg_match('/%(?:2f|00|(?![a-f0-9]{2}))/i', $path) === 1) {
                return new RoutingError(400);
            }

            $path = rawurldecode($path);
        }
        if (str_contains($path, "\0")) {
            return new RoutingError(400);
        }

        $methods = $this->exact[$path] ?? null;
        if ($methods !== null) {
            return $methods[$method] ?? ($method === Method::HEAD ? $methods[Method::GET] ?? null : null)
                ?? self::methodNotAllowed(array_keys($methods));
        }

        $slash = strrpos($path, '/');
        if ($slash !== false && $slash + 1 < \strlen($path)) {
            $prefix = substr($path, 0, $slash + 1);
            $routes = $this->trailing[$prefix] ?? null;
            if ($routes !== null) {
                $route = $routes[$method] ?? ($method === Method::HEAD ? $routes[Method::GET] ?? null : null);
                if ($route === null) {
                    return self::methodNotAllowed(array_keys($routes));
                }

                return self::matched($route[0], [$route[1] => substr($path, $slash + 1)]);
            }
        }

        $segments = explode('/', $path);

        foreach ($this->patterns[\count($segments)] ?? [] as $shape) {
            $key = implode('/', array_replace($segments, $shape['mask']));
            $routes = $shape['paths'][$key] ?? null;
            if ($routes === null) {
                continue;
            }

            foreach ($shape['mask'] as $index => $_) {
                // @phpstan-ignore offsetAccess.notFound (the table is indexed by segment count)
                if ($segments[$index] === '') {
                    continue 2;
                }
            }

            $route = $routes[$method] ?? ($method === Method::HEAD ? $routes[Method::GET] ?? null : null);
            if ($route === null) {
                return self::methodNotAllowed(array_keys($routes));
            }

            $params = [];
            foreach ($route[1] as $index => $name) {
                // @phpstan-ignore offsetAccess.notFound (the name belongs to this path shape)
                $params[$name] = $segments[$index];
            }

            return self::matched($route[0], $params);
        }

        return new RoutingError(404);
    }

    /**
     * @param array<non-empty-string, string> $params
     * @return Dispatch
     */
    private static function matched(Route $route, array $params = []): array
    {
        return [$route->handler, new RouteMatch($route->method, $route->route, $params)];
    }

    private function register(Route $route): void
    {
        $method = $route->method;
        $pattern = $route->route;

        if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $method) !== 1) {
            throw new \InvalidArgumentException("Invalid HTTP method '{$method}'.");
        }
        if (!str_starts_with($pattern, '/') || str_contains($pattern, "\0")) {
            throw new \InvalidArgumentException("Invalid route pattern '{$pattern}'.");
        }

        $segments = explode('/', $pattern);
        $names = self::parameterNames($segments, $pattern);
        if ($names === []) {
            if (isset($this->exact[$pattern][$method])) {
                throw new \InvalidArgumentException("Duplicate route {$method} {$pattern}.");
            }

            $this->exact[$pattern][$method] = self::matched($route);

            return;
        }

        $count = \count($segments);
        $last = array_key_last($segments);
        if (\count($names) === 1 && isset($names[$last])) {
            $prefix = substr($pattern, 0, -\strlen($segments[$last]));
            if (isset($this->trailing[$prefix][$method])) {
                throw new \InvalidArgumentException("Duplicate route {$method} {$pattern}.");
            }

            $this->trailing[$prefix][$method] = [$route, $names[$last]];

            return;
        }

        $mask = array_fill_keys(array_keys($names), ':');
        $priority = str_repeat('1', $count);
        foreach ($names as $index => $_) {
            $priority[$index] = '0';
        }

        $key = implode('/', array_replace($segments, $mask));
        $this->patterns[$count][$priority] ??= ['mask' => $mask, 'paths' => []];

        if (isset($this->patterns[$count][$priority]['paths'][$key][$method])) {
            throw new \InvalidArgumentException("Duplicate route {$method} {$pattern}.");
        }

        $this->patterns[$count][$priority]['paths'][$key][$method] = [$route, $names];
    }

    /**
     * @param non-empty-list<string> $segments
     * @return array<int, non-empty-string>
     */
    private static function parameterNames(array $segments, string $pattern): array
    {
        $names = [];
        foreach ($segments as $index => $segment) {
            if (!str_starts_with($segment, ':')) {
                continue;
            }

            $name = substr($segment, 1);
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1 || \in_array($name, $names, true)) {
                throw new \InvalidArgumentException("Invalid parameter '{$segment}' in '{$pattern}'.");
            }

            $names[$index] = $name;
        }

        return $names;
    }

    /**
     * @param list<int|string> $methods
     */
    private static function methodNotAllowed(array $methods): RoutingError
    {
        if (\in_array(Method::GET, $methods, true) && !\in_array(Method::HEAD, $methods, true)) {
            $methods[] = Method::HEAD;
        }

        return new RoutingError(405, array_map(strval(...), $methods));
    }
}
