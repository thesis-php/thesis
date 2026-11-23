<?php

declare(strict_types=1);

namespace Thesis\Http\Http1;

use Thesis\Http\Headers;
use Thesis\Http\Internal\Http1\Fields;

/**
 * Encodes a chunk of a body sent with `Transfer-Encoding: chunked`.
 *
 * @api
 * @param non-empty-string $bytes an empty chunk would mean the last chunk and end the body
 */
function encodeChunk(string $bytes): string
{
    return dechex(\strlen($bytes)) . "\r\n{$bytes}\r\n";
}

/**
 * Encodes the last chunk of a body, followed by the trailers.
 *
 * @api
 * @throws \ValueError if the trailers contain a field that frames or routes a message
 */
function encodeLastChunk(Headers $trailers = new Headers()): string
{
    foreach (Fields::NOT_IN_TRAILERS as $name) {
        if ($trailers->has($name)) {
            throw new \ValueError(\sprintf('The field "%s" must not be in trailers.', $name));
        }
    }

    return implode("\r\n", ['0', ...Fields::formatLines($trailers)]) . "\r\n\r\n";
}
