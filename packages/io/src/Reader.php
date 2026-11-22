<?php

declare(strict_types=1);

namespace Thesis\Io;

use Thesis\Async\Cancelled;

/**
 * @api
 */
interface Reader
{
    /**
     * Reads at least 1 and at most $max bytes, waiting (suspending the fiber) while there are none.
     * A read that throws consumes no bytes, even if the exception is {@see Cancelled}.
     * This lets {@see Buffer::fill()} keep all bytes it has read if a later read is cancelled.
     *
     * Wait for the outstanding read on this reader to finish before starting another one.
     *
     * Catch {@see IoException} to handle all I/O failures.
     *
     * @param positive-int $max
     * @return non-empty-string
     * @throws UnexpectedEof when the stream reaches its end and no bytes remain to be read
     * @throws ReadFailed on a read error such as a connection reset
     * @throws IoException if close() was called on this reader before the read or while the read was waiting.
     *         {@see Stream} throws {@see StreamClosed}. A thesis/net socket throws `SocketClosed`.
     * @throws Cancelled if the scope is cancelled at a suspension point during the read
     * @throws \LogicException if another fiber is already reading from this reader
     */
    public function read(int $max): string;
}
