<?php

declare(strict_types=1);

namespace Thesis\Io;

/**
 * @api
 */
interface Writer
{
    /**
     * Writes all the given bytes, waiting (suspending the fiber) while they do not fit. An empty string is a no-op.
     * A write that throws before writing anything leaves the writer as it was. One that throws after writing
     * a part leaves it unusable (a stream closes itself): the other side has got half a message.
     * Only a writer without messages may go on after the half and says so (`stdout()`).
     *
     * @throws WriteFailed
     * @throws IoException of the implementation if this side has closed it ({@see StreamClosed},
     *         `SocketClosed` of thesis/net)
     */
    public function write(string $bytes): void;
}
