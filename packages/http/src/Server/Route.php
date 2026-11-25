<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Http\Response;

/**
 * @api
 */
final readonly class Route
{
    /**
     * @param callable(Request, Context): Response $handler
     * @param array<non-empty-string, string> $requirements
     * @param array<non-empty-string, string> $defaults
     */
    public static function get(string $pattern, callable $handler, array $requirements = [], array $defaults = []): self
    {
        return new self(Method::GET, $pattern, $handler, $requirements, $defaults);
    }

    /**
     * @param callable(Request, Context): Response $handler
     * @param array<non-empty-string, string> $requirements
     * @param array<non-empty-string, string> $defaults
     */
    public static function head(
        string $pattern,
        callable $handler,
        array $requirements = [],
        array $defaults = [],
    ): self {
        return new self(Method::HEAD, $pattern, $handler, $requirements, $defaults);
    }

    /**
     * @param callable(Request, Context): Response $handler
     * @param array<non-empty-string, string> $requirements
     * @param array<non-empty-string, string> $defaults
     */
    public static function post(
        string $pattern,
        callable $handler,
        array $requirements = [],
        array $defaults = [],
    ): self {
        return new self(Method::POST, $pattern, $handler, $requirements, $defaults);
    }

    /**
     * @param callable(Request, Context): Response $handler
     * @param array<non-empty-string, string> $requirements
     * @param array<non-empty-string, string> $defaults
     */
    public static function put(string $pattern, callable $handler, array $requirements = [], array $defaults = []): self
    {
        return new self(Method::PUT, $pattern, $handler, $requirements, $defaults);
    }

    /**
     * @param callable(Request, Context): Response $handler
     * @param array<non-empty-string, string> $requirements
     * @param array<non-empty-string, string> $defaults
     */
    public static function patch(
        string $pattern,
        callable $handler,
        array $requirements = [],
        array $defaults = [],
    ): self {
        return new self(Method::PATCH, $pattern, $handler, $requirements, $defaults);
    }

    /**
     * @param callable(Request, Context): Response $handler
     * @param array<non-empty-string, string> $requirements
     * @param array<non-empty-string, string> $defaults
     */
    public static function delete(
        string $pattern,
        callable $handler,
        array $requirements = [],
        array $defaults = [],
    ): self {
        return new self(Method::DELETE, $pattern, $handler, $requirements, $defaults);
    }

    /**
     * @param callable(Request, Context): Response $handler
     * @param array<non-empty-string, string> $requirements
     * @param array<non-empty-string, string> $defaults
     */
    public static function options(
        string $pattern,
        callable $handler,
        array $requirements = [],
        array $defaults = [],
    ): self {
        return new self(Method::OPTIONS, $pattern, $handler, $requirements, $defaults);
    }

    /**
     * @param callable(Request, Context): Response $handler
     * @param array<non-empty-string, string> $requirements
     * @param array<non-empty-string, string> $defaults
     */
    public static function connect(
        string $pattern,
        callable $handler,
        array $requirements = [],
        array $defaults = [],
    ): self {
        return new self(Method::CONNECT, $pattern, $handler, $requirements, $defaults);
    }

    /**
     * @param callable(Request, Context): Response $handler
     * @param array<non-empty-string, string> $requirements
     * @param array<non-empty-string, string> $defaults
     */
    public static function trace(
        string $pattern,
        callable $handler,
        array $requirements = [],
        array $defaults = [],
    ): self {
        return new self(Method::TRACE, $pattern, $handler, $requirements, $defaults);
    }

    /**
     * @param callable(Request, Context): Response $handler
     * @param array<non-empty-string, string> $requirements
     * @param array<non-empty-string, string> $defaults
     */
    public static function any(string $pattern, callable $handler, array $requirements = [], array $defaults = []): self
    {
        return new self(null, $pattern, $handler, $requirements, $defaults);
    }

    /**
     * @var non-empty-list<non-empty-string>|null
     */
    public ?array $methods;

    /**
     * @var \Closure(Request, Context): Response
     */
    public \Closure $handler;

    /**
     * @param non-empty-string|non-empty-list<non-empty-string>|null $methods
     * @param callable(Request, Context): Response $handler
     * @param array<non-empty-string, string> $requirements
     * @param array<non-empty-string, string> $defaults
     */
    public function __construct(
        null|string|array $methods,
        public string $pattern,
        callable $handler,
        public array $requirements = [],
        public array $defaults = [],
    ) {
        $this->methods = \is_string($methods) ? [$methods] : $methods;
        $this->handler = $handler(...);
    }
}
