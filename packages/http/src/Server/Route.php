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
     * @var \Closure(Request, Context): Response
     */
    public \Closure $handler;

    /**
     * @param non-empty-string $method
     * @param callable(Request, Context): Response $handler
     */
    public function __construct(
        public string $method,
        public string $route,
        callable $handler,
    ) {
        $this->handler = $handler(...);
    }
}
