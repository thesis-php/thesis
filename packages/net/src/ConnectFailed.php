<?php

declare(strict_types=1);

namespace Thesis\Net;

use Thesis\Io\IoException;

/**
 * A connection could not be established: the host is not found, refuses or is unreachable.
 *
 * @api
 */
final class ConnectFailed extends IoException {}
