<?php

declare(strict_types=1);

namespace Thesis\Amqp;

/**
 * No configured host accepted the connection. The previous exception records the last host's failure.
 *
 * @api
 */
final class ConnectionFailed extends AmqpException {}
