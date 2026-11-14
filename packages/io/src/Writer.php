<?php

declare(strict_types=1);

namespace Thesis\Io;

/**
 * @api
 */
interface Writer
{
    /**
     * Writes all the given bytes. An empty string is a no-op.
     *
     * @throws WriteFailed
     */
    public function write(string $bytes): void;
}
