<?php

declare(strict_types=1);

namespace Thesis\Io;

/**
 * @api
 */
interface Writer
{
    /**
     * `write()` writes all the given bytes, waiting (suspending the fiber) while they do not fit. An empty string
     * is a no-op.
     * A write that throws before sending any bytes leaves the writer as it was. A write that throws after sending
     * a part leaves it unusable (a stream closes itself): the other side has got half a message.
     * Only writers without message boundaries may continue after a partial write. Such writers document
     * that behavior, as `stdout()` does.
     *
     * @throws WriteFailed
     * @throws IoException of the implementation if this side has closed it ({@see StreamClosed},
     *         `SocketClosed` of thesis/net)
     */
    public function write(string $bytes): void;
}
