<?php

declare(strict_types=1);

namespace Thesis\Net;

use Thesis\Io\IoException;

/**
 * A server socket could not be bound: the address is in use, not local or not permitted.
 *
 * @api
 */
final class ListenFailed extends IoException {}
