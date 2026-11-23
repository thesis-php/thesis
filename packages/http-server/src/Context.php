<?php

declare(strict_types=1);

namespace Thesis\HttpServer;

use Thesis\Net\Address;
use Thesis\Net\TlsInfo;

/**
 * The transport details of the connection that sent a request.
 *
 * @api
 */
final readonly class Context
{
    /**
     * @param ?TlsInfo $tls the negotiated TLS details, or null for a plaintext connection
     */
    public function __construct(
        public Address $localAddress,
        public Address $remoteAddress,
        public ?TlsInfo $tls,
    ) {}
}
