<?php

declare(strict_types=1);

namespace Thesis\Http\Internal\Http1;

/**
 * The framing tells the recipient where the message body ends on the connection.
 *
 * @internal
 */
enum Framing
{
    /**
     * No body follows the head: a message without `Content-Length` and `Transfer-Encoding`, a response
     * to `HEAD`, a 1xx, 204 or 304 response, a 2xx response to `CONNECT`.
     */
    case None;

    /**
     * The body is as many bytes as `Content-Length` says.
     */
    case Length;

    /**
     * The body is a series of chunks that ends with the last chunk (`Transfer-Encoding: chunked`).
     */
    case Chunked;

    /**
     * The body is everything the peer sends until it closes the connection: a response without
     * `Content-Length` and `Transfer-Encoding`.
     */
    case UntilClose;
}
