<?php

declare(strict_types=1);

namespace Thesis\Amqp;

/**
 * No host of the config accepted the connection: the previous exception is the failure of the last one.
 *
 * @api
 */
final class ConnectionFailed extends AmqpException {}
