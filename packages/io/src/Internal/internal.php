<?php

declare(strict_types=1);

namespace Thesis\Io\Internal;

/**
 * The message of the error the last suppressed call raised, without the name of the function.
 * Call error_clear_last() before that call.
 *
 * @internal
 * @param non-empty-string $default if the call raised nothing
 * @return non-empty-string
 */
function lastError(string $default): string
{
    $message = error_get_last()['message'] ?? '';
    $message = preg_replace('/^\w+\(\): /', '', $message) ?? $message;

    return $message === '' ? $default : $message;
}

/**
 * A {@see SharedStream} over a duplicate of a standard descriptor: close() closes the duplicate,
 * the original stays for `echo` and the STDOUT constant.
 *
 * @internal
 * @param 'php://stdin'|'php://stdout'|'php://stderr' $path
 */
function stdio(string $path, string $mode): SharedStream
{
    $resource = fopen($path, $mode);

    if ($resource === false) {
        throw new \RuntimeException(\sprintf('Failed to open %s.', $path));
    }

    return new SharedStream($resource);
}
