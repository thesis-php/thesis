<?php

declare(strict_types=1);

namespace Thesis\Net;

/**
 * An end of a socket: {@see InetAddress} for TCP, {@see UnixAddress} for Unix sockets.
 *
 * @api
 * @phpstan-sealed InetAddress|UnixAddress
 */
interface Address extends \Stringable {}
