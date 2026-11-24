<?php

declare(strict_types=1);

namespace Thesis\Http;

/**
 * @api
 */
interface ReplayableBody extends Body
{
    /**
     * Opens an independent reader at the beginning with the same bytes and trailers.
     * The original can still open readers after it has been consumed or closed.
     */
    public function fresh(): self;
}
