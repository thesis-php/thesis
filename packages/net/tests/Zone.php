<?php

declare(strict_types=1);

namespace Thesis\Net;

use Thesis\Net\Internal\Dns\Message;

/**
 * What {@see FakeDns} answers for a name.
 */
final readonly class Zone
{
    /**
     * @param list<non-empty-string> $a
     * @param list<non-empty-string> $aaaa
     * @param ?non-empty-string $cname a CNAME record put before the addresses
     * @param bool $truncated whether to answer over UDP with the TC flag and no records
     * @param bool $silent whether to never answer
     */
    public function __construct(
        public array $a = [],
        public array $aaaa = [],
        public int $ttl = 60,
        public int $rcode = Message::NOERROR,
        public ?string $cname = null,
        public bool $truncated = false,
        public bool $silent = false,
    ) {}
}
