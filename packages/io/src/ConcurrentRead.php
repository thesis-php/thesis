<?php

declare(strict_types=1);

namespace Thesis\Io;

/**
 * A read was attempted on a reader that already has a read in progress.
 *
 * @api
 */
final class ConcurrentRead extends \LogicException {}
