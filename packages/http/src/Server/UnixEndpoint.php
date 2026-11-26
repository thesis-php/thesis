<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Thesis\Net\ServerTls;

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
     * @param positive-int|null $maxConnections connection limit for this endpoint, or null to use the config default
     */
    public function __construct(
        public string $path,
        public int $backlog = 511,
        public ?ServerTls $tls = null,
        public ?int $maxConnections = null,
    ) {}
}
