<?php

declare(strict_types=1);

namespace Thesis\Http\Client;

use Thesis\Http\Internal\Client\DecodedBody;
use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Http\Response;

/**
 * Negotiates gzip and deflate for full responses. Explicit negotiation leaves the encoded body to the caller.
 *
 * @api
 */
final readonly class DecodeContent implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if ($request->headers->has('accept-encoding') || $request->headers->has('range')) {
            return $next($request);
        }

        $response = $next($request->withHeader('accept-encoding', 'gzip, deflate'));
        if ($request->method === Method::HEAD || $response->status < 200
            || \in_array($response->status, [204, 205, 206, 304], strict: true)) {
            return $response;
        }

        $encoding = match (strtolower($response->headers->get('content-encoding') ?? '')) {
            'gzip', 'x-gzip' => ZLIB_ENCODING_GZIP,
            'deflate' => ZLIB_ENCODING_DEFLATE,
            default => null,
        };

        if ($encoding === null) {
            return $response;
        }

        return $response
            ->withHeaders($response->headers->without('content-length', 'content-encoding'))
            ->withBody(new DecodedBody($response->takeBody(), $encoding))
        ;
    }
}
