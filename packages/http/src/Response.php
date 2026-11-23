<?php

declare(strict_types=1);

namespace Thesis\Http;

use Thesis\Io\Reader;
use const Thesis\Http\Internal\EMPTY_HEADERS;

/**
 * HTTP/1.1 and HTTP/2 clients and servers share this response type. The response holds a status,
 * message headers and a body. The codec gets the length from the body. The headers exclude connection
 * fields such as `Content-Length`, `Transfer-Encoding` and `Connection`. The response has no reason phrase
 * because HTTP/2 does not use reason phrases.
 *
 * The properties are `public(set)` so that `clone($response, ['status' => Status::NOT_FOUND])` can make a changed copy.
 * The copy shares the reader with the original.
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
        string|Reader $body = '',
    ) {
        $this->body = $body instanceof Body ? $body : new Body($body);
    }
}
