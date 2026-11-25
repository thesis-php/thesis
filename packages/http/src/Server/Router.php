<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

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
    /**
     * @var array<string, array<array-key, array{Route, RouteMatch}>>
     */
    private array $exact = [];

    /**
     * @var array<string, array<array-key, array{Route, array{non-empty-string}}>>
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

        foreach ($this->patterns as &$shapes) {
            krsort($shapes, SORT_STRING);
        }
    }

    public function __invoke(Request $request, Context $context): Response
    {
        $path = self::decodePath($request);
        if ($path === null) {
            return $context->errorHandler->handleError(Status::BAD_REQUEST, $request);
        }

        if (isset($this->exact[$path])) {
            return self::dispatch($this->exact[$path], [], $request, $context);
        }

        return $this->matchTrailing($path, $request, $context)
            ?? $this->matchPattern($path, $request, $context)
            ?? $context->errorHandler->handleError(Status::NOT_FOUND, $request);
    }

    private static function decodePath(Request $request): ?string
    {
        $path = $request->uri->getRawPath();
        if (
            $path === ''
            && $request->uri->getScheme() !== null
            && $request->method !== Method::CONNECT
            && ($request->method !== Method::OPTIONS || $request->uri->getRawQuery() !== null)
        ) {
            return '/';
        }

        if (str_contains($path, '%')) {
            if (preg_match('/%(?:2f|00|(?![a-f0-9]{2}))/i', $path) === 1) {
                return null;
            }

            $path = rawurldecode($path);
        }

        return str_contains($path, "\0") ? null : $path;
    }

    private function matchTrailing(string $path, Request $request, Context $context): ?Response
    {
        $slash = strrpos($path, '/');
        if ($slash === false || $slash === \strlen($path) - 1) {
            return null;
        }

        $prefix = substr($path, 0, $slash + 1);
        if (!isset($this->trailing[$prefix])) {
            return null;
        }

        return self::dispatch($this->trailing[$prefix], [substr($path, $slash + 1)], $request, $context);
    }

    private function matchPattern(string $path, Request $request, Context $context): ?Response
    {
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

            return self::dispatch($routes, $segments, $request, $context);
        }

        return null;
    }

    private function register(Route $route): void
    {
        $pattern = $route->route;

        if (!str_starts_with($pattern, '/') || str_contains($pattern, "\0")) {
            throw new \InvalidArgumentException("Invalid route pattern '{$pattern}'.");
        }

        $segments = explode('/', $pattern);
        $names = self::parameterNames($segments, $pattern);
        $last = array_key_last($segments);
        if ($names === []) {
            $routes = &$this->exact[$pattern];
        } elseif (\count($names) === 1 && isset($names[$last])) {
            $prefix = substr($pattern, 0, -\strlen($segments[$last]));
            $routes = &$this->trailing[$prefix];
            $names = [$names[$last]];
        } else {
            $count = \count($segments);
            $mask = array_fill_keys(array_keys($names), ':');
            // Sort literals before parameters at the first segment where two shapes differ.
            $priority = str_repeat('1', $count);
            foreach ($names as $index => $_) {
                $priority = substr_replace($priority, '0', $index, 1);
            }

            $key = implode('/', array_replace($segments, $mask));
            $this->patterns[$count][$priority] ??= ['mask' => $mask, 'paths' => []];
            $routes = &$this->patterns[$count][$priority]['paths'][$key];
        }

        foreach ($route->methods as $method) {
            if (!isToken($method)) {
                throw new \InvalidArgumentException("Invalid HTTP method '{$method}'.");
            }
            if (isset($routes[$method])) {
                throw new \InvalidArgumentException("Duplicate route {$method} {$pattern}.");
            }

            $routes[$method] = [$route, $names === [] ? new RouteMatch($method, $pattern) : $names];
        }
    }

    /**
     * @param array<array-key, array{Route, RouteMatch|array<int, non-empty-string>}> $routes
     * @param list<string> $segments
     */
    private static function dispatch(array $routes, array $segments, Request $request, Context $context): Response
    {
        $method = $request->method;
        if ($method === Method::HEAD && !isset($routes[Method::HEAD])) {
            $method = Method::GET;
        }
        if (!isset($routes[$method])) {
            return self::methodNotAllowed($request, $context, array_keys($routes));
        }

        [$route, $match] = $routes[$method];
        if (!$match instanceof RouteMatch) {
            $params = [];
            foreach ($match as $index => $name) {
                // @phpstan-ignore offsetAccess.notFound (parameter positions belong to the matched path shape)
                $params[$name] = $segments[$index];
            }

            $match = new RouteMatch($method, $route->route, $params);
        }

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
    private static function methodNotAllowed(Request $request, Context $context, array $methods): Response
    {
        if (\in_array(Method::GET, $methods, true) && !\in_array(Method::HEAD, $methods, true)) {
            $methods[] = Method::HEAD;
        }

        $response = $context->errorHandler->handleError(Status::METHOD_NOT_ALLOWED, $request);

        return $response->withHeader('Allow', implode(', ', $methods));
    }
}
