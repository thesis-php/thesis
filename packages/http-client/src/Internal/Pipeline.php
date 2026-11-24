<?php

declare(strict_types=1);

namespace Thesis\HttpClient\Internal;

use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\HttpClient\Middleware;

/**
 * @internal
 */
final class Pipeline
{
    /**
     * @param callable(Request): Response $send
     * @param list<Middleware> $middleware
     * @return \Closure(Request): Response
     */
    public static function wrap(callable $send, array $middleware): \Closure
    {
        $next = $send(...);
        foreach (array_reverse($middleware) as $handler) {
            $next = static fn(Request $request) => $handler->handle($request, $next);
        }

        return $next;
    }
}
