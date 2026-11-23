<?php

declare(strict_types=1);

namespace Thesis\Http;

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
