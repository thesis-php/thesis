<?php

declare(strict_types=1);

namespace Thesis\HttpServer;

/**
 * A listening Unix socket path and its queue limit.
 *
 * @api
 */
final readonly class UnixEndpoint
{
    /**
     * @param non-empty-string $path the socket path to bind
     * @param positive-int $backlog the maximum number of pending connections, capped by the OS
     */
    public function __construct(
        public string $path,
        public int $backlog = 511,
    ) {}
}
