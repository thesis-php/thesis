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
    private bool $expired = false;

    private bool $running = false;

    private readonly int $index;

    /**
     * @param list<Middleware> $middleware
     * @param \Closure(Request, Context): Response $handler
     */
    public function __construct(
        private readonly array $middleware,
        private readonly \Closure $handler,
        private readonly Request $request,
        private readonly Context $context,
    ) {
        $this->index = 0;
    }

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
            $layer = $this->middleware[$this->index] ?? null;
            if ($layer === null) {
                return ($this->handler)($this->request, $this->context);
            }

            $next = clone($this, ['index' => $this->index + 1, 'running' => false]);

            try {
                return $layer->handle($this->request, $next);
            } finally {
                $next->expired = true;
            }
        } finally {
            $this->running = false;
        }
    }
}
