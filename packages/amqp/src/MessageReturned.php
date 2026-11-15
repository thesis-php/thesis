<?php

declare(strict_types=1);

namespace Thesis\Amqp;

/**
 * A message published with `mandatory: true` in confirm mode was routed to no queue (basic.return).
 *
 * @api
 */
final class MessageReturned extends AmqpException
{
    public function __construct(
        public readonly ReplyCode $replyCode,
        public readonly string $replyText,
        public readonly string $exchange,
        public readonly string $routingKey,
        public readonly Message $returnedMessage,
    ) {
        parent::__construct(
            message: "The message to exchange \"{$exchange}\" with routing key \"{$routingKey}\" was returned: {$replyText}.",
        );
    }
}
