<?php

declare(strict_types=1);

namespace Thesis\Http\Internal\Server;

use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Server\Context;
use Thesis\Http\Server\Middleware;

/**
 * @internal
 * @phpstan-import-type HandlerClosure from \Thesis\Http\Server
 */
final class Next
{
    private bool $expired = false;

    private bool $running = false;

    private readonly int $index;

    /**
     * @param non-empty-list<Middleware> $middleware
     * @param HandlerClosure $handler
     */
    public function __construct(
        private readonly array $middleware,
        private readonly \Closure $handler,
        private readonly Request $request,
        private readonly Context $context,
    ) {
        $this->index = 1;
    }

    public function handle(Middleware $middleware): Response
    {
        try {
            return $middleware->handle($this->request, $this->context, $this);
        } finally {
            $this->expired = true;
        }
    }

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
            $middleware = $this->middleware[$this->index] ?? null;

            if ($middleware === null) {
                return ($this->handler)($this->request, $this->context);
            }

            $next = clone($this, ['index' => $this->index + 1, 'running' => false]);

            return $next->handle($middleware);
        } finally {
            $this->running = false;
        }
    }

    private function __clone() {}
}
