<?php

declare(strict_types=1);

namespace Thesis\Io;

use function Thesis\Io\Internal\stdio;

/**
 * The standard input of the process as a non-blocking {@see Stream}, the same object on every call.
 * Only the stream is non-blocking: the descriptor, shared with the shell and other processes, stays blocking.
 *
 * @api
 */
function stdin(): Stream
{
    /** @var Stream $stream */
    static $stream = stdio('php://stdin', 'r');

    return $stream;
}

/**
 * The standard output of the process as a non-blocking {@see Stream}, the same object on every call.
 * The descriptor stays blocking for everyone else, `echo` included: an `echo` while a write of the stream
 * waits for room blocks the process as usual and may land in the middle of that write.
 *
 * @api
 */
function stdout(): Stream
{
    /** @var Stream $stream */
    static $stream = stdio('php://stdout', 'w');

    return $stream;
}

/**
 * The standard error of the process as a non-blocking {@see Stream}, the same object on every call.
 * The same as for {@see stdout()} applies to `STDERR` and to PHP's own error output.
 *
 * @api
 */
function stderr(): Stream
{
    /** @var Stream $stream */
    static $stream = stdio('php://stderr', 'w');

    return $stream;
}
