<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Thesis\Net\InetAddress;
use Thesis\Net\ServerTls;
use Time\Duration;
use function Thesis\Time\sec;

/**
 * The listening endpoint, HTTP limits and optional TLS settings. Creating a config does no I/O.
 *
 * @api
 */
final readonly class Config
{
    public TcpEndpoint|UnixEndpoint $endpoint;

    public Duration $headTimeout;

    public Duration $idleTimeout;

    public Duration $bodyIdleTimeout;

    public Duration $writeTimeout;

    public Duration $tlsHandshakeTimeout;

    public Duration $shutdownTimeout;

    /**
     * @param int<0, 65535>|non-empty-string|InetAddress|TcpEndpoint|UnixEndpoint $endpoint an IPv4 loopback port
     *     (zero lets the OS choose), an absolute Unix socket path, a TCP address in IP:port or [IPv6]:port form,
     *     or a configured endpoint
     * @param ?ServerTls $tls the TLS settings, or null to serve plaintext
     * @param positive-int $maxConnections connections served at the same time
     * @param positive-int|null $maxRequestsPerConnection requests allowed on one connection, or null for no limit
     * @param positive-int $maxHeadBytes bytes allowed per request head, including the final empty line
     * @param non-negative-int $maxBodyBytes decoded bytes allowed per request body
     * @param ?Duration $headTimeout time to receive a complete request head, defaults to 10 seconds
     * @param ?Duration $idleTimeout time to wait for the next request, defaults to 30 seconds
     * @param ?Duration $bodyIdleTimeout time without input while reading a body, defaults to 30 seconds
     * @param ?Duration $writeTimeout time to complete each write, defaults to 30 seconds
     * @param ?Duration $tlsHandshakeTimeout time to complete a TLS handshake, defaults to 10 seconds
     * @param ?Duration $shutdownTimeout time for active exchanges to finish after shutdown, defaults to 5 seconds
     * @throws \ValueError if a timeout is negative
     * @throws \ValueError if an endpoint string is neither an absolute Unix path nor a valid TCP address
     */
    public function __construct(
        int|string|InetAddress|TcpEndpoint|UnixEndpoint $endpoint,
        public ?ServerTls $tls = null,
        public int $maxConnections = 1024,
        public ?int $maxRequestsPerConnection = null,
        public int $maxHeadBytes = 64 * 1024,
        public int $maxBodyBytes = 16 * 1024 * 1024,
        ?Duration $headTimeout = null,
        ?Duration $idleTimeout = null,
        ?Duration $bodyIdleTimeout = null,
        ?Duration $writeTimeout = null,
        ?Duration $tlsHandshakeTimeout = null,
        ?Duration $shutdownTimeout = null,
    ) {
        $this->endpoint = match (true) {
            \is_int($endpoint) => new TcpEndpoint(new InetAddress('127.0.0.1', $endpoint)),
            \is_string($endpoint) => str_starts_with($endpoint, '/')
                ? new UnixEndpoint($endpoint)
                : new TcpEndpoint(InetAddress::fromString($endpoint)),
            $endpoint instanceof InetAddress => new TcpEndpoint($endpoint),
            $endpoint instanceof TcpEndpoint, $endpoint instanceof UnixEndpoint => $endpoint,
        };
        $this->headTimeout = $headTimeout ?? sec(10);
        $this->idleTimeout = $idleTimeout ?? sec(30);
        $this->bodyIdleTimeout = $bodyIdleTimeout ?? sec(30);
        $this->writeTimeout = $writeTimeout ?? sec(30);
        $this->tlsHandshakeTimeout = $tlsHandshakeTimeout ?? sec(10);
        $this->shutdownTimeout = $shutdownTimeout ?? sec(5);

        foreach ([
            'headTimeout' => $this->headTimeout,
            'idleTimeout' => $this->idleTimeout,
            'bodyIdleTimeout' => $this->bodyIdleTimeout,
            'writeTimeout' => $this->writeTimeout,
            'tlsHandshakeTimeout' => $this->tlsHandshakeTimeout,
            'shutdownTimeout' => $this->shutdownTimeout,
        ] as $name => $timeout) {
            if ($timeout->negative) {
                throw new \ValueError("The {$name} option must be a non-negative duration.");
            }
        }
    }
}
