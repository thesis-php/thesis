<?php

declare(strict_types=1);

namespace Thesis\Http\Internal\Server;

use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Server\Context;
use Thesis\Http\Server\Middleware;

/**
 * @internal
 */
final class Next
{
    /**
     * @param list<Middleware> $middleware
     * @param \Closure(Request, Context): Response $handler
     */
    public static function dispatch(
        array $middleware,
        \Closure $handler,
        Request $request,
        Context $context,
        int $index,
    ): Response {
        $layer = $middleware[$index] ?? null;
        if ($layer === null) {
            return $handler($request, $context);
        }

        $next = new self($middleware, $handler, $request, $context, $index + 1);

        try {
            return $layer->handle($request, $next);
        } finally {
            $next->expired = true;
        }
    }

    private bool $expired = false;

    private bool $running = false;

    /**
     * @param list<Middleware> $middleware
     * @param \Closure(Request, Context): Response $handler
     */
    private function __construct(
        private readonly array $middleware,
        private readonly \Closure $handler,
        private readonly Request $request,
        private readonly Context $context,
        private readonly int $index,
    ) {}

    private function __clone() {}

    /**
     * @throws \LogicException if the middleware invocation has ended or a previous call is still running
     */
    public function __invoke(): Response
    {
        if ($this->expired) {
            throw new \LogicException('The middleware invocation has ended.');
        }

        if ($this->running) {
            throw new \LogicException('The next handler is already running.');
        }

        $this->running = true;

        try {
            return self::dispatch($this->middleware, $this->handler, $this->request, $this->context, $this->index);
        } finally {
            $this->running = false;
        }
    }
}
