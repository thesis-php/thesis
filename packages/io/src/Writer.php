<?php

declare(strict_types=1);

namespace Thesis\Io;

use Thesis\Async\Cancelled;

/**
 * @api
 */
interface Writer
{
    /**
     * `write()` writes all the given bytes, waiting (suspending the fiber) while they do not fit. An empty string
     * is a no-op.
     * A write that throws before sending any bytes leaves the writer as it was. A write that throws after sending
     * some bytes leaves the writer unusable because the receiver may have only part of a message.
     * A stream closes itself in that case.
     * Only writers without message boundaries may continue after a partial write. Such writers document
     * that behavior, as `stdout()` does.
     *
     * @throws WriteFailed on a write error such as a broken pipe
     * @throws IoException if the writer was closed before a non-empty write or while the write was waiting.
     *         {@see Stream} throws {@see StreamClosed}. A thesis/net socket throws `SocketClosed`.
     * @throws Cancelled if the scope is cancelled at a suspension point during the write
     */
    public function write(string $bytes): void;
}
