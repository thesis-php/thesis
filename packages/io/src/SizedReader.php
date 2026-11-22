<?php

declare(strict_types=1);

namespace Thesis\Io;

/**
 * A reader with a known total size in bytes.
 *
 * @api
 */
interface SizedReader extends Reader
{
    /**
     * @var non-negative-int
     */
    public int $size { get; }
}
