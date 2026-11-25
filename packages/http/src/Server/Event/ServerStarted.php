<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Event;

use Thesis\Net\Address;

/**
 * @api
 */
final readonly class ServerStarted
{
    public function __construct(
        public Address $address,
        public bool $tls = false,
    ) {}
}
