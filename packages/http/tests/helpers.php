<?php

declare(strict_types=1);

namespace Thesis\Http;

use Testo\Assert;
use Uri\Rfc3986\Uri;

function getRequest(string|Uri $uri): Request
{
    return new Request(Method::GET, $uri);
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
 * @param callable(): mixed $fn
 */
function thrown(callable $fn): \Throwable
{
    try {
        $fn();
    } catch (\Throwable $exception) {
        return $exception;
    }

    Assert::fail('Nothing was thrown.');
}

/**
 * Removes the variable timestamp so wire assertions can compare the remaining bytes.
 */
function withoutResponseDate(string $bytes): string
{
    return (string) preg_replace('/(HTTP\/1\.[01] [2-5]\d\d [^\r\n]*)\r\nDate: [^\r\n]+ GMT/', '$1', $bytes);
}
