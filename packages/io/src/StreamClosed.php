<?php

declare(strict_types=1);

namespace Thesis\Io;

/**
 * The stream was closed by this side: an operation after close(), or a wait that close() interrupted.
 *
 * @api
 */
final class StreamClosed extends IoException {}
