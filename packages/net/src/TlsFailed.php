<?php

declare(strict_types=1);

namespace Thesis\Net;

use Thesis\Io\IoException;

/**
 * The TLS handshake failed: the certificate is not trusted or not valid for the peer name, no common protocol.
 *
 * @api
 */
final class TlsFailed extends IoException {}
