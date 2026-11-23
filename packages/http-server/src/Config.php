<?php

declare(strict_types=1);

namespace Thesis\HttpServer;

use Thesis\Net\InetAddress;
use Thesis\Net\ServerTls;

/**
 * The listening endpoint, HTTP limits and optional TLS settings. Creating a config does no I/O.
 *
 * @api
 */
final readonly class Config
{
    /**
     * @param non-empty-string $host an IPv4 or IPv6 address
     * @param int<0, 65535> $port zero lets the operating system choose the port
     * @param positive-int $backlog
     */
    public static function tcp(
        string $host,
        int $port,
        Options $options = new Options(),
        ?ServerTls $tls = null,
        int $backlog = 511,
        bool $reusePort = false,
        bool $noDelay = true,
    ): self {
        return new self(new InetAddress($host, $port), $options, $tls, $backlog, $reusePort, $noDelay);
    }

    /**
     * @param non-empty-string $path
     * @param positive-int $backlog
     */
    public static function unix(
        string $path,
        Options $options = new Options(),
        ?ServerTls $tls = null,
        int $backlog = 511,
    ): self {
        return new self($path, $options, $tls, $backlog);
    }

    /**
     * @param InetAddress|non-empty-string $endpoint TCP address or Unix socket path
     * @param positive-int $backlog
     */
    private function __construct(
        public InetAddress|string $endpoint,
        public Options $options,
        public ?ServerTls $tls,
        public int $backlog,
        public bool $reusePort = false,
        public bool $noDelay = true,
    ) {}
}
