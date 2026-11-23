<?php

declare(strict_types=1);

namespace Thesis\Http;

/**
 * HTTP/1.1 and HTTP/2 clients and servers share this response type. The response holds a status,
 * message headers and a body. `Content-Length` can describe the representation on a HEAD or 304 response.
 * The codec derives the transmitted body length from the body. The response has no reason phrase because
 * HTTP/2 does not use reason phrases.
 *
 * The properties are `public(set)` so that `clone($response, ['status' => Status::NOT_FOUND])` can make a changed copy.
 * The copy shares the body with the original.
 *
 * @api
 */
final readonly class Response
{
    public public(set) Body $body;

    /**
     * @param int<100, 999> $status anyone may define a status code
     */
    public function __construct(
        public public(set) int $status = Status::OK,
        public public(set) Headers $headers = EMPTY_HEADERS,
        string|Body $body = '',
    ) {
        $this->body = \is_string($body) ? new StringBody($body) : $body;
    }
}
