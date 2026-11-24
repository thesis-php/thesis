<?php

declare(strict_types=1);

namespace Thesis\Http;

/**
 * @api
 */
interface ClientMiddleware
{
    /**
     * Pass the outgoing request to the next handler, or return a response directly.
     * Redirects and retries may call the next handler again. Before another attempt,
     * close any intermediate response body and prepare a fresh upload if needed.
     *
     * @param callable(Request): Response $next
     */
    public function handle(Request $request, callable $next): Response;
}
