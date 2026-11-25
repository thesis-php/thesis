<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Thesis\Http\Internal\Server\Next;
use Thesis\Http\Request;
use Thesis\Http\Response;

/**
 * Wraps a request handler with ordered server middleware.
 *
 * @api
 */
final readonly class Pipeline
{
    /**
     * @var \Closure(Request, Context): Response
     */
    private \Closure $handler;

    /**
     * @param callable(Request, Context): Response $handler
     * @param list<Middleware> $middleware outermost first
     */
    public function __construct(
        callable $handler,
        private array $middleware,
    ) {
        $this->handler = $handler(...);
    }

    public function __invoke(Request $request, Context $context): Response
    {
        return new Next($this->middleware, $this->handler, $request, $context)();
    }
}
