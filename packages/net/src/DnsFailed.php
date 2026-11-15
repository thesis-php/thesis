<?php

declare(strict_types=1);

namespace Thesis\Net;

use Thesis\Io\IoException;

/**
 * A host name could not be resolved: it does not exist, or no name server answered.
 *
 * @api
 */
final class DnsFailed extends IoException {}
