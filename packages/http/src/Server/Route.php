<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Thesis\Http\Method;

/**
 * @api
 * @phpstan-import-type Handler from \Thesis\Http\Server
 * @phpstan-import-type HandlerClosure from \Thesis\Http\Server
 */
final readonly class Route
{
    /**
     * @param Handler $handler
     * @param array<non-empty-string, string> $requirements
     * @param array<non-empty-string, string> $defaults
     */
    public static function get(string $pattern, callable $handler, array $requirements = [], array $defaults = []): self
    {
        return new self(Method::GET, $pattern, $handler, $requirements, $defaults);
    }

    /**
     * @param Handler $handler
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
     * @param Handler $handler
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
     * @param Handler $handler
     * @param array<non-empty-string, string> $requirements
     * @param array<non-empty-string, string> $defaults
     */
    public static function put(string $pattern, callable $handler, array $requirements = [], array $defaults = []): self
    {
        return new self(Method::PUT, $pattern, $handler, $requirements, $defaults);
    }

    /**
     * @param Handler $handler
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
     * @param Handler $handler
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
     * @param Handler $handler
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
     * @param Handler $handler
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
     * @param Handler $handler
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
     * @param Handler $handler
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
     * @var HandlerClosure
     */
    public \Closure $handler;

    /**
     * @param non-empty-string|non-empty-list<non-empty-string>|null $methods
     * @param Handler $handler
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
