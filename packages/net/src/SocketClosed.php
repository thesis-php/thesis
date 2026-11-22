<?php

declare(strict_types=1);

namespace Thesis\Net;

use Thesis\Io\IoException;

/**
 * A socket operation started after {@see Socket::close()} or was waiting when close() was called on the socket.
 *
 * @api
 */
final class SocketClosed extends IoException {}
