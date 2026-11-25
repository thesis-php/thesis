<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Event;

use Thesis\Net\Address;

/**
 * An exception escaped while the server was reading a request, running its handler or writing the response.
 *
 * @api
 */
final readonly class ConnectionHandlingFailed
{
    public function __construct(
        public Address $localAddress,
        public Address $remoteAddress,
        public \Throwable $error,
    ) {}
}
