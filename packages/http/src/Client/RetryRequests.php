<?php

declare(strict_types=1);

namespace Thesis\Http\Client;

use Thesis\Http\Method;
use Thesis\Http\ReplayableBody;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Io\ReadFailed;
use Thesis\Io\WriteFailed;
use Thesis\Net\ConnectFailed;

/**
 * Retries idempotent requests with replayable bodies after a connection, read or write failure before the response.
 * Install immediately before the transport.
 *
 * @api
 */
final readonly class RetryRequests implements Middleware
{
    /**
     * @param non-negative-int $maxRetries additional attempts after the first failure
     */
    public function __construct(
        private int $maxRetries = 1,
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        if ($this->maxRetries === 0 || !\in_array(
            $request->method,
            [Method::GET, Method::HEAD, Method::OPTIONS, Method::TRACE, Method::PUT, Method::DELETE],
            strict: true,
        )) {
            return $next($request);
        }

        $body = $request->takeBody();
        for ($retries = 0;; ++$retries) {
            try {
                return $next($request->withBody($body));
            } catch (ConnectFailed|ReadFailed|WriteFailed $error) {
                if (!$body instanceof ReplayableBody || $retries >= $this->maxRetries) {
                    throw $error;
                }
            }

            $body = $body->fresh();
        }
    }
}
