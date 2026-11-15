<?php

declare(strict_types=1);

namespace Thesis\Io;

use Thesis\Io\Internal\SharedStream;
use function Thesis\Io\Internal\stdio;

/**
 * The standard input of the process, the same object on every call. A read waits through the event loop
 * without making the descriptor non-blocking: it is shared with the shell and other processes.
 * Throws `LogicException` on Windows.
 *
 * @api
 */
function stdin(): Reader&Closer
{
    /** @var SharedStream $stream */
    static $stream = stdio('php://stdin', 'r');

    return $stream;
}

/**
 * The standard output of the process, the same object on every call. A write waits through the event loop
 * without making the descriptor non-blocking, so `echo` and the processes that share it keep working.
 * A write cancelled after a part leaves the stream open: the next one goes on after the half.
 * Throws `LogicException` on Windows.
 *
 * @api
 */
function stdout(): Writer&Closer
{
    /** @var SharedStream $stream */
    static $stream = stdio('php://stdout', 'w');

    return $stream;
}

/**
 * The standard error of the process, the same as {@see stdout()}.
 *
 * @api
 */
function stderr(): Writer&Closer
{
    /** @var SharedStream $stream */
    static $stream = stdio('php://stderr', 'w');

    return $stream;
}
