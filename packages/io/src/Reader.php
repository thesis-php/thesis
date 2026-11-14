<?php

declare(strict_types=1);

namespace Thesis\Io;

/**
 * @api
 */
interface Reader
{
    /**
     * Reads at least 1 and at most $max bytes.
     *
     * @param positive-int $max
     * @return non-empty-string
     * @throws UnexpectedEof at the end of the stream
     * @throws ReadFailed if the read fails for another reason
     */
    public function read(int $max): string;
}
