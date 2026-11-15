<?php

declare(strict_types=1);

namespace Thesis\Io\Internal;

use Thesis\Io\Stream;

/**
 * A {@see Stream::shared()} over a duplicate of a standard descriptor: {@see Stream::close()} closes
 * the duplicate, the original stays for `echo` and the STDOUT constant.
 *
 * @internal
 * @param 'php://stdin'|'php://stdout'|'php://stderr' $path
 */
function stdio(string $path, string $mode): Stream
{
    $resource = fopen($path, $mode);

    if ($resource === false) {
        throw new \RuntimeException(\sprintf('Failed to open %s.', $path));
    }

    return Stream::shared($resource);
}
