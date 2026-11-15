<?php

declare(strict_types=1);

namespace Thesis\Io\Internal;

/**
 * A {@see SharedStream} over a duplicate of a standard descriptor: close() closes the duplicate,
 * the original stays for `echo` and the STDOUT constant.
 *
 * @internal
 * @param 'php://stdin'|'php://stdout'|'php://stderr' $path
 * @throws \LogicException on Windows, where a pipe can be neither selected nor watched
 */
function stdio(string $path, string $mode): SharedStream
{
    if (PHP_OS_FAMILY === 'Windows') {
        throw new \LogicException('Non-blocking stdio is not supported on Windows.');
    }

    $resource = fopen($path, $mode);

    if ($resource === false) {
        throw new \RuntimeException(\sprintf('Failed to open %s.', $path));
    }

    return new SharedStream($resource);
}
