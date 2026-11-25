<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Net\Address;
use Thesis\Net\TlsInfo;

/**
 * The connection details and the server's error handler.
 *
 * @api
 */
final readonly class Context implements ErrorHandler
{
    /**
     * @param ?TlsInfo $tls the negotiated TLS details, or null for a plaintext connection
     */
    public function __construct(
        public Address $localAddress,
        public Address $remoteAddress,
        public ?TlsInfo $tls,
        private ErrorHandler $errorHandler,
    ) {}

    public function handle(int $status, Request $request): Response
    {
        $response = $this->errorHandler->handle($status, $request);
        if ($response->status !== $status) {
            throw new \ValueError('The error handler must preserve the status.');
        }

        return $response;
    }
}
