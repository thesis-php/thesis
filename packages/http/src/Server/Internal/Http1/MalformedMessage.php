<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Internal\Http1;

use Thesis\Http\Status;
use Thesis\Io\IoException;

/**
 * Thrown when the bytes from the peer are not a valid HTTP/1.1 message, or when the codec refuses to guess
 * the meaning of a message that two recipients could understand differently. After this exception
 * the connection cannot be read any further: the end of the message is unknown.
 *
 * @internal
 */
final class MalformedMessage extends IoException
{
    /**
     * @param int<400, 599> $status the status of the response a server sends to such a request
     */
    public function __construct(
        string $message,
        public readonly int $status = Status::BAD_REQUEST,
    ) {
        parent::__construct($message);
    }
}
