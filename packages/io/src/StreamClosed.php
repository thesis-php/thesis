<?php

declare(strict_types=1);

namespace Thesis\Io;

/**
 * A stream operation started after close() was called on the stream or was waiting when close() was called.
 *
 * @api
 */
final class StreamClosed extends IoException {}
