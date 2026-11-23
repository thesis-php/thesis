<?php

declare(strict_types=1);

namespace Thesis\HttpServer;

use Time\Duration;
use function Thesis\Time\sec;

/**
 * @api
 */
final readonly class Options
{
    public Duration $headTimeout;

    public Duration $bodyIdleTimeout;

    public Duration $writeTimeout;

    public Duration $idleTimeout;

    public Duration $shutdownTimeout;

    public Duration $tlsHandshakeTimeout;

    /**
     * @param ?Duration $headTimeout time to receive a complete request head, defaults to 10 seconds
     * @param ?Duration $bodyIdleTimeout time without input while reading a body, defaults to 30 seconds
     * @param ?Duration $writeTimeout time to complete each write, defaults to 30 seconds
     * @param non-negative-int $maxBodyBytes decoded bytes allowed per request body
     * @param positive-int $maxHeadBytes bytes allowed per request head, including the final empty line
     * @param positive-int $maxRequestsPerConnection requests allowed before the server closes the connection
     * @param ?Duration $idleTimeout time to wait for the next request, defaults to 30 seconds
     * @param positive-int $maxConnections connections served at the same time
     * @param ?Duration $shutdownTimeout time for active exchanges to finish after shutdown, defaults to 5 seconds
     * @param ?Duration $tlsHandshakeTimeout time to complete a TLS handshake, defaults to 10 seconds
     * @throws \ValueError if a timeout is negative
     */
    public function __construct(
        ?Duration $headTimeout = null,
        ?Duration $bodyIdleTimeout = null,
        ?Duration $writeTimeout = null,
        public int $maxBodyBytes = 16 * 1024 * 1024,
        public int $maxHeadBytes = 64 * 1024,
        public int $maxRequestsPerConnection = 1000,
        ?Duration $idleTimeout = null,
        public int $maxConnections = 1024,
        ?Duration $shutdownTimeout = null,
        ?Duration $tlsHandshakeTimeout = null,
    ) {
        $this->headTimeout = $headTimeout ?? sec(10);
        $this->bodyIdleTimeout = $bodyIdleTimeout ?? sec(30);
        $this->writeTimeout = $writeTimeout ?? sec(30);
        $this->idleTimeout = $idleTimeout ?? sec(30);
        $this->shutdownTimeout = $shutdownTimeout ?? sec(5);
        $this->tlsHandshakeTimeout = $tlsHandshakeTimeout ?? sec(10);

        foreach ([
            'headTimeout' => $this->headTimeout,
            'bodyIdleTimeout' => $this->bodyIdleTimeout,
            'writeTimeout' => $this->writeTimeout,
            'idleTimeout' => $this->idleTimeout,
            'shutdownTimeout' => $this->shutdownTimeout,
            'tlsHandshakeTimeout' => $this->tlsHandshakeTimeout,
        ] as $name => $timeout) {
            if ($timeout->negative) {
                throw new \ValueError("The {$name} option must be a non-negative duration.");
            }
        }
    }
}
