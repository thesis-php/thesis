<?php

declare(strict_types=1);

namespace Thesis\HttpClient;

use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\StringBody;
use Thesis\Io\ReadFailed;
use Thesis\Io\WriteFailed;
use Thesis\Net\ConnectFailed;

/**
 * Retries idempotent requests with string bodies after a connection, read or write failure before the response.
 * Install immediately before the transport. Each attempt reads the complete string from the beginning.
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
        $body = $request->body;
        if ($this->maxRetries === 0 || !$body instanceof StringBody || !\in_array(
            $request->method,
            [Method::GET, Method::HEAD, Method::OPTIONS, Method::TRACE, Method::PUT, Method::DELETE],
            strict: true,
        )) {
            return $next($request);
        }

        for ($retries = 0;; ++$retries) {
            try {
                return $next(clone($request, ['body' => $body->fresh()]));
            } catch (ConnectFailed|ReadFailed|WriteFailed $error) {
                if ($retries >= $this->maxRetries) {
                    throw $error;
                }
            }
        }
    }
}
