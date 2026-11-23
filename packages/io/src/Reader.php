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
     * Only one read per reader may be in progress. Different fibers may take turns reading from the same reader.
     * An overlapping call immediately throws {@see ConcurrentRead} and leaves the pending read in progress.
     * The usual write and close contracts apply while a read is pending.
     *
     * Catch {@see IoException} to handle all I/O failures.
     *
     * @param positive-int $max
     * @return ?non-empty-string null when the stream has ended
     * @throws ConcurrentRead if a read is already in progress on this reader
     * @throws ReadFailed on a read error such as a connection reset
     * @throws IoException if close() was called on this reader before the read or while the read was waiting.
     *         {@see Stream} throws {@see StreamClosed}. A thesis/net socket throws `SocketClosed`.
     * @throws Cancelled if the scope is cancelled at a suspension point during the read
     */
    public function read(int $max): ?string;
}
