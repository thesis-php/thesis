<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Server\Internal\Next;

/**
 * Wraps a request handler with ordered server middleware.
 *
 * @api
 * @phpstan-import-type Handler from \Thesis\Http\Server
 * @phpstan-import-type HandlerClosure from \Thesis\Http\Server
 */
final readonly class Pipeline
{
    /**
     * @param Handler $handler
     * @param iterable<Middleware> $middleware outermost first
     * @return Handler the original handler when the iterable is empty
     */
    public static function wrap(callable $handler, iterable $middleware): callable
    {
        $layers = iterator_to_array($middleware, preserve_keys: false);

        return $layers === [] ? $handler : new self($handler(...), $layers);
    }

    /**
     * @param HandlerClosure $handler
     * @param non-empty-list<Middleware> $middleware
     */
    private function __construct(
        private \Closure $handler,
        private array $middleware,
    ) {}

    public function __invoke(Request $request, Context $context): Response
    {
        $next = new Next($this->middleware, $this->handler, $request, $context);

        return $next->handle($this->middleware[0]);
    }
}
