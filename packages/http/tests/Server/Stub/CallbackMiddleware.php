<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Stub;

use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Server\Middleware;

final readonly class CallbackMiddleware implements Middleware
{
    /**
     * @param \Closure(Request, callable(): Response): Response $callback
     */
    public function __construct(
        private \Closure $callback,
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        return ($this->callback)($request, $next);
    }
}
