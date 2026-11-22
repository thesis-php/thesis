<?php

declare(strict_types=1);

namespace Thesis\Net\Internal\Dns;

/**
 * @internal
 */
final readonly class Response
{
    /**
     * @param int<0, 15> $rcode
     * @param list<non-empty-string> $addresses the addresses of the requested record type, after the server
     *     follows CNAME chains
     * @param non-negative-int $ttl the smallest TTL across the addresses, in seconds, or 0 if there are no addresses
     */
    public function __construct(
        public bool $truncated,
        public int $rcode,
        public array $addresses,
        public int $ttl,
    ) {}
}
