<?php

declare(strict_types=1);

namespace Thesis\Io;

/**
 * @api
 */
interface Closer
{
    /**
     * Releases the underlying resource. Must be idempotent.
     */
    public function close(): void;
}
