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
     * @param list<non-empty-string> $addresses of the type asked for, CNAME chains followed by the server
     * @param non-negative-int $ttl the smallest of $addresses, 0 if there are none
     */
    public function __construct(
        public bool $truncated,
        public int $rcode,
        public array $addresses,
        public int $ttl,
    ) {}
}
