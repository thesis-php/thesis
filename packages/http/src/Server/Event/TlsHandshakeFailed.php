<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Event;

use Thesis\Net\Address;

/**
 * @api
 */
final readonly class TlsHandshakeFailed
{
    public function __construct(
        public Address $localAddress,
        public Address $remoteAddress,
        public \Throwable $error,
    ) {}
}
