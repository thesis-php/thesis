<?php

declare(strict_types=1);

namespace Thesis\Io;

/**
 * @api
 */
interface Closer
{
    /**
     * Releases the resources it owns without reading any remaining input.
     * Repeated calls have no further effect.
     */
    public function close(): void;
}
