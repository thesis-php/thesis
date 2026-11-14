<?php

declare(strict_types=1);

namespace Thesis\Io;

/**
 * A read failed for a reason other than the end of the stream: a connection reset, a TLS error.
 *
 * @api
 */
final class ReadFailed extends IoException {}
