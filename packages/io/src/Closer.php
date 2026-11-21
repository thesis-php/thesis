<?php

declare(strict_types=1);

namespace Thesis\Io;

/**
 * @api
 */
interface Closer
{
    /**
     * `close()` releases the underlying resource. Repeated calls must have no further effect.
     */
    public function close(): void;
}
