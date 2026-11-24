<?php

declare(strict_types=1);

namespace Thesis\Http;

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
