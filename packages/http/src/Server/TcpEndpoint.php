<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Thesis\Net\InetAddress;
use Thesis\Net\ServerTls;

/**
 * A TCP listening address and its socket settings.
 *
 * @api
 */
final readonly class TcpEndpoint
{
    /**
     * @param InetAddress $address the address to bind, with port zero to let the OS choose a port
     * @param positive-int $backlog the maximum number of pending connections, capped by the OS
     * @param bool $reusePort lets several processes listen on the same port
     * @param bool $noDelay disables Nagle's algorithm on accepted sockets
     * @param positive-int|null $maxConnections connection limit for this endpoint, or null to use the config default
     */
    public function __construct(
        public InetAddress $address,
        public int $backlog = 511,
        public bool $reusePort = false,
        public bool $noDelay = true,
        public ?ServerTls $tls = null,
        public ?int $maxConnections = null,
    ) {}
}
