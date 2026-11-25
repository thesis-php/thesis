<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Thesis\Http\Request;
use Thesis\Http\Response;

/**
 * @api
 */
final readonly class Route
{
    /**
     * @var non-empty-list<non-empty-string>
     */
    public array $methods;

    /**
     * @var \Closure(Request, Context): Response
     */
    public \Closure $handler;

    /**
     * @param non-empty-string|non-empty-list<non-empty-string> $methods
     * @param callable(Request, Context): Response $handler
     * @param array<non-empty-string, string> $requirements
     * @param array<non-empty-string, string> $defaults
     */
    public function __construct(
        string|array $methods,
        public string $route,
        callable $handler,
        public array $requirements = [],
        public array $defaults = [],
    ) {
        $this->methods = (array) $methods;
        $this->handler = $handler(...);
    }
}
