<?php

declare(strict_types=1);

namespace Thesis\Net;

/**
 * A socket endpoint uses {@see InetAddress} for TCP or {@see UnixAddress} for a Unix socket.
 *
 * @api
 * @phpstan-sealed InetAddress|UnixAddress
 */
interface Address extends \Stringable {}
