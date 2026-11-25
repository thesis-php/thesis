<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Thesis\Http\Request;
use Thesis\Http\Response;

/**
 * @api
 */
interface Middleware
{
    /**
     * Return a response directly or delegate to the next handler for this incoming request.
     * While handle() is running, it can call $next again after a previous call returns or throws.
     * Calling $next concurrently or after this invocation of handle() ends throws a \LogicException.
     *
     * @param callable(): Response $next
     */
    public function handle(Request $request, Context $context, callable $next): Response;
}
