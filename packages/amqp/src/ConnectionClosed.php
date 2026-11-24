<?php

declare(strict_types=1);

namespace Thesis\Amqp;

/**
 * The connection is gone, and with it every channel, consumer and publish waiting for a confirm on it.
 *
 * Closed by the server, {@see $replyCode} tells why (`ConnectionForced` when the broker stops,
 * `AccessRefused` for wrong credentials). Lost (a reset, missed heartbeats), it is null and the previous exception
 * is the cause. Closed by {@see Client::stop()}, it is `ReplySuccess`.
 *
 * @api
 */
final class ConnectionClosed extends AmqpException
{
    public function __construct(
        string $message,
        public readonly ?ReplyCode $replyCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
