<?php

declare(strict_types=1);

namespace Thesis\Http;

/**
 * @api
 */
interface ServerMiddleware
{
    /**
     * Return a response directly or call the next handler once for this incoming request.
     *
     * @param callable(): Response $next
     */
    public function handle(Request $request, callable $next): Response;
}
