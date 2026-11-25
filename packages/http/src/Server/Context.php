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
final readonly class Context
{
    /**
     * @param ?TlsInfo $tls the negotiated TLS details, or null for a plaintext connection
     */
    public function __construct(
        public Address $localAddress,
        public Address $remoteAddress,
        public ?TlsInfo $tls,
        private ErrorHandler $errorHandler = new DefaultErrorHandler(),
    ) {}

    /**
     * @param int<400, 599> $status
     * @throws \ValueError if the error handler returns a different status
     */
    public function handleError(int $status, Request $request): Response
    {
        $response = $this->errorHandler->handleError($status, $request);
        if ($response->status !== $status) {
            throw new \ValueError('The error handler must preserve the status.');
        }

        return $response;
    }
}
