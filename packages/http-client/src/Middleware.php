<?php

declare(strict_types=1);

namespace Thesis\HttpClient;

use Thesis\Http\Request;
use Thesis\Http\Response;

/**
 * @api
 */
interface Middleware
{
    /**
     * Call the next handler to send a request, or supply a response directly. Close every intermediate response.
     *
     * @param callable(Request): Response $next
     */
    public function handle(Request $request, callable $next): Response;
}
