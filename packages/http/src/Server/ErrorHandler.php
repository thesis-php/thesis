<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Thesis\Http\Request;
use Thesis\Http\Response;

/**
 * @api
 */
interface ErrorHandler
{
    /**
     * Returns a response with the supplied status.
     *
     * @param int<400, 599> $status
     */
    public function handle(int $status, Request $request): Response;
}
