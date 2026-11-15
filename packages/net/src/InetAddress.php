<?php

declare(strict_types=1);

namespace Thesis\Net;

/**
 * An IP address and a port.
 *
 * @api
 */
final readonly class InetAddress implements Address
{
    /**
     * @param non-empty-string $ip IPv4 or IPv6, without brackets
     * @param int<0, 65535> $port
     * @throws \ValueError if $ip is not an IP address
     */
    public function __construct(
        public string $ip,
        public int $port,
    ) {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new \ValueError(\sprintf('"%s" is not an IP address.', $ip));
        }
    }

    /**
     * @return non-empty-string `127.0.0.1:80` or `[::1]:80`
     */
    public function __toString(): string
    {
        return str_contains($this->ip, ':') ? "[{$this->ip}]:{$this->port}" : "{$this->ip}:{$this->port}";
    }
}
