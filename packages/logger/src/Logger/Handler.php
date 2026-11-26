<?php

declare(strict_types=1);

namespace Thesis\Logger;

/**
 * Receives log calls that pass the logger's level filter.
 *
 * @api
 */
interface Handler
{
    /**
     * Capture the record on the calling fiber before returning, or skip capture when dropping the call.
     *
     * @param \Closure(): Record $capture
     */
    public function handle(\Closure $capture): void;
}
