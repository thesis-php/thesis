<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Internal\Http1;

use Thesis\Http\Headers;
use const Thesis\Http\EMPTY_HEADERS;

/**
 * Encodes a chunk of a body sent with `Transfer-Encoding: chunked`.
 *
 * @internal
 * @param non-empty-string $bytes an empty chunk would mean the last chunk and end the body
 */
function encodeChunk(string $bytes): string
{
    return dechex(\strlen($bytes)) . "\r\n{$bytes}\r\n";
}

/**
 * Encodes the last chunk of a body, followed by the trailers.
 *
 * @internal
 * @throws \ValueError if the trailers contain a field that frames or routes a message
 */
function encodeLastChunk(Headers $trailers = EMPTY_HEADERS): string
{
    if ($trailers->isEmpty) {
        return "0\r\n\r\n";
    }

    foreach (Fields::NOT_IN_TRAILERS as $name) {
        if ($trailers->has($name)) {
            throw new \ValueError(\sprintf('The field "%s" must not be in trailers.', $name));
        }
    }

    return "0\r\n" . Fields::formatLines($trailers) . "\r\n";
}
