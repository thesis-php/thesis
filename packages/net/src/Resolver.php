<?php

declare(strict_types=1);

namespace Thesis\Net;

use Thesis\Async\Cancelled;

/**
 * Turns a host name into IP addresses for {@see connect()}. An implementation must not block the event loop:
 * `gethostbyname()` and `dns_get_record()` do.
 *
 * @api
 */
interface Resolver
{
    /**
     * @param non-empty-string $host
     * @return non-empty-list<non-empty-string> IP addresses in the order of preference, IPv6 without brackets
     * @throws DnsFailed
     * @throws Cancelled if cancellation of the scope interrupts host resolution
     */
    public function resolve(string $host): array;
}
