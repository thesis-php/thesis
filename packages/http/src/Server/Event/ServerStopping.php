<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Event;

use Thesis\Net\Address;
use Time\Duration;

/**
 * @api
 */
final readonly class ServerStopping
{
    public function __construct(
        public Address $address,
        public int $connections,
        public Duration $timeout,
    ) {}
}
