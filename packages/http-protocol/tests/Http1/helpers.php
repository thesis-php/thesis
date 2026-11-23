<?php

declare(strict_types=1);

namespace Thesis\HttpProtocol\Http1;

use Thesis\Http\Headers;
use Thesis\Io\Buffer;
use Thesis\Io\Reader;

/**
 * `readAll()` reads the reader to its end, asking for at most $max bytes at a time.
 *
 * @param positive-int $max
 */
function readAll(Reader $reader, int $max = 65_536): string
{
    $bytes = '';

    while (($chunk = $reader->read($max)) !== null) {
        $bytes .= $chunk;
    }

    return $bytes;
}

/**
 * The lines of the headers as pairs of a name and a value, in the order of iteration. Not an array
 * indexed by name: PHP would turn a numeric name into an int key.
 *
 * @return list<array{string, string}>
 */
function lines(Headers $headers): array
{
    $pairs = [];

    foreach ($headers as $name => $lines) {
        foreach ($lines as $line) {
            $pairs[] = [$name, $line];
        }
    }

    return $pairs;
}

/**
 * The message of the \ValueError that the call throws, null if the call does not throw it.
 *
 * @param callable(): mixed $call
 */
function failure(callable $call): ?string
{
    try {
        $call();
    } catch (\ValueError $error) {
        return $error->getMessage();
    }

    return null;
}

/**
 * The status and the message of the MalformedMessage that the call throws, null if the call does not throw it.
 *
 * @param callable(): mixed $call
 */
function rejection(callable $call): ?string
{
    try {
        $call();
    } catch (MalformedMessage $malformed) {
        return "{$malformed->status}: {$malformed->getMessage()}";
    }

    return null;
}

/**
 * Joins the lines of a head with CRLF, without the empty line that ends the head.
 *
 * @no-named-arguments
 */
function head(string ...$lines): string
{
    return implode("\r\n", $lines);
}

/**
 * `requestHead()` parses the head of a request as a server does. An HTTP/1.1 request gets `Host: example.com` added.
 *
 * @no-named-arguments
 */
function requestHead(string $requestLine, string ...$fields): RequestHead
{
    if (str_ends_with($requestLine, 'HTTP/1.1')) {
        $fields = ['Host: example.com', ...$fields];
    }

    return RequestHead::parse(head($requestLine, ...$fields));
}

/**
 * A ChunkedReader over the given bytes, with the default limits.
 */
function chunked(string $bytes): ChunkedReader
{
    return new ChunkedReader(new Buffer(), new ScriptedReader($bytes));
}
