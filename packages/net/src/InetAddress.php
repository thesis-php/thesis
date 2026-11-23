<?php

declare(strict_types=1);

namespace Thesis\Net;

/**
 * A TCP endpoint has an IP address and a port.
 *
 * @api
 */
final readonly class InetAddress implements Address
{
    /**
     * @param string $address an IPv4 address with a port or a bracketed IPv6 address with a port
     * @throws \ValueError if the address is malformed or the port is outside 0 to 65535
     */
    public static function fromString(string $address): self
    {
        if (preg_match('/\A(\[[0-9a-f:.]*:[0-9a-f:.]*\]|[0-9.]+):([0-9]+)\z/i', $address, $parts) !== 1) {
            throw new \ValueError('Expected an IPv4 address with a port or a bracketed IPv6 address with a port.');
        }

        $ip = trim($parts[1], '[]');
        $port = (int) $parts[2];
        \assert($ip !== '' && $port >= 0);

        if ($port > 65535) {
            throw new \ValueError('The port must be between 0 and 65535.');
        }

        return new self($ip, $port);
    }

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
