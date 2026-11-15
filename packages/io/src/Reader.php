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
     * A read that throws, {@see Cancelled} included, has taken nothing: {@see Buffer::fill()}
     * relies on it, so a cancelled fill loses no bytes.
     *
     * @param positive-int $max
     * @return non-empty-string
     * @throws UnexpectedEof at the end of the stream
     * @throws ReadFailed if the read fails for another reason
     */
    public function read(int $max): string;
}
