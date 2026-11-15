<?php

declare(strict_types=1);

namespace Thesis\Net;

use Thesis\Io\IoException;

/**
 * The socket was closed by this side: an operation after close(), or a wait that close() interrupted.
 *
 * @api
 */
final class SocketClosed extends IoException {}
