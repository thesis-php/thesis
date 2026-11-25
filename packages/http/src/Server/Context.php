<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Thesis\Dispatcher;
use Thesis\Net\Address;
use Thesis\Net\TlsInfo;

/**
 * The connection details and the server's error handler.
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
        public ErrorHandler $errorHandler,
        public Dispatcher $dispatcher,
    ) {}
}
