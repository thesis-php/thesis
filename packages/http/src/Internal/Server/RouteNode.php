<?php

declare(strict_types=1);

namespace Thesis\Http\Internal\Server;

use Thesis\Http\Method;
use Thesis\Http\Server\Route;
use Thesis\Http\Server\RouteMatch;

/**
 * @internal
 */
final class RouteNode
{
    /**
     * @var array<string, self>
     */
    public array $literals = [];

    /**
     * @var array<string, array{RouteSegment, self}>
     */
    public array $expressions = [];

    public bool $literalOnly = true;

    public ?self $parameter = null;

    public ?self $remainder = null;

    /**
     * @var array<string, self>
     */
    public array $remainderExpressions = [];

    public ?self $optional = null;

    /**
     * @var array<array-key, array{Route, list<non-empty-string>}|array{Route, RouteMatch}>
     */
    private array $routes = [];

    /**
     * @param list<non-empty-string> $names
     */
    public function register(Route $route, array $names): void
    {
        foreach ($route->methods as $method) {
            if (isset($this->routes[$method])) {
                throw new \InvalidArgumentException("Duplicate route {$method} {$route->route}.");
            }
            $match = $names === [] ? new RouteMatch($method, $route->route, $route->defaults) : $names;
            $this->routes[$method] = [$route, $match];
        }
    }

    /**
     * @param list<string> $segments
     * @param list<string> $values
     * @return array{Route, RouteMatch}|string|null
     */
    public function find(array $segments, int $offset, array $values, string $method): null|array|string
    {
        $node = $this;

        while (($segment = $segments[$offset] ?? null) !== null) {
            ++$offset;
            if ($node->literalOnly) {
                $child = $node->literals[$segment] ?? null;
                if ($child === null) {
                    return null;
                }
                $node = $child;

                continue;
            }
            $literal = $node->literals[$segment] ?? null;
            $hasRemainder = $node->remainder !== null || $node->remainderExpressions !== [];
            if ($literal !== null) {
                if (!$hasRemainder && ($segment === '' || ($node->parameter === null && $node->expressions === []))) {
                    $node = $literal;

                    continue;
                }
                $match = $literal->find($segments, $offset, $values, $method);
                if ($match !== null) {
                    return $match;
                }
            }
            if ($segment !== '') {
                foreach ($node->expressions as [$expression, $child]) {
                    $captures = $expression->match($segment);
                    if ($captures !== null) {
                        $match = $child->find($segments, $offset, [...$values, ...$captures], $method);
                        if ($match !== null) {
                            return $match;
                        }
                    }
                }
                if ($node->parameter !== null) {
                    if (!$hasRemainder) {
                        $values[] = $segment;
                        $node = $node->parameter;

                        continue;
                    }
                    $match = $node->parameter->find($segments, $offset, [...$values, $segment], $method);
                    if ($match !== null) {
                        return $match;
                    }
                }
            }
            if (!$hasRemainder) {
                return null;
            }

            return $node->matchRemainder(implode('/', \array_slice($segments, $offset - 1)), $values, $method);
        }
        if ($node->routes !== []) {
            return $node->select($values, $method);
        }

        return $node->optional?->select($values, $method) ?? $node->matchRemainder('', $values, $method);
    }

    /**
     * @param list<string> $values
     * @return array{Route, RouteMatch}|string|null
     */
    private function matchRemainder(string $tail, array $values, string $method): null|array|string
    {
        $node = $this->remainder;
        foreach ($this->remainderExpressions as $regex => $candidate) {
            if ($tail === '' && $candidate->optional === null) {
                continue;
            }
            $result = preg_match($regex, $tail);
            if ($result === false) {
                throw new \RuntimeException(preg_last_error_msg());
            }
            if ($result === 1) {
                $node = $candidate;
                break;
            }
        }
        if ($tail === '') {
            $node = $node?->optional;
        }

        return $node?->select([...$values, $tail], $method);
    }

    /**
     * @param list<string> $values
     * @return array{Route, RouteMatch}|string
     */
    public function select(array $values, string $method): array|string
    {
        if ($method === Method::HEAD && !isset($this->routes[$method])) {
            $method = Method::GET;
        }
        if (!isset($this->routes[$method])) {
            $methods = array_keys($this->routes);
            if (isset($this->routes[Method::GET]) && !isset($this->routes[Method::HEAD])) {
                $methods[] = Method::HEAD;
            }

            return implode(', ', $methods);
        }

        $entry = $this->routes[$method];
        if ($entry[1] instanceof RouteMatch) {
            return $entry;
        }
        [$route, $names] = $entry;
        $params = array_combine($names, $values);
        if ($route->defaults !== []) {
            $params += $route->defaults;
        }

        return [$route, new RouteMatch($method, $route->route, $params)];
    }
}
