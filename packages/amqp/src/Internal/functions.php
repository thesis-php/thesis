<?php

declare(strict_types=1);

namespace Thesis\Amqp\Internal;

use Thesis\Amqp\DeliveryMode;
use Thesis\Amqp\Internal\Protocol\BasicProperties;
use Thesis\Amqp\Message;
use function Thesis\Time\ms;
use function Thesis\Time\toMs;

/**
 * @internal
 */
function properties(Message $message): BasicProperties
{
    return new BasicProperties(
        contentType: $message->contentType,
        contentEncoding: $message->contentEncoding,
        headers: $message->headers === [] ? null : $message->headers,
        deliveryMode: $message->deliveryMode?->value,
        priority: $message->priority,
        correlationId: $message->correlationId,
        replyTo: $message->replyTo,
        expiration: $message->expiration === null
            ? null
            : (string) max(0, toMs($message->expiration, \RoundingMode::PositiveInfinity)),
        messageId: $message->messageId,
        timestamp: $message->timestamp === null ? null : max(0, $message->timestamp->getTimestamp()),
        type: $message->type,
        userId: $message->userId,
        appId: $message->appId,
    );
}

/**
 * An expiration that is not whole milliseconds (another client may send anything) is dropped:
 * RabbitMQ itself rejects such a message.
 *
 * @internal
 */
function message(string $body, BasicProperties $properties): Message
{
    $expiration = $properties->expiration;

    return new Message(
        body: $body,
        headers: $properties->headers ?? [],
        contentType: $properties->contentType,
        contentEncoding: $properties->contentEncoding,
        deliveryMode: $properties->deliveryMode === null ? null : DeliveryMode::tryFrom($properties->deliveryMode),
        priority: $properties->priority,
        correlationId: $properties->correlationId,
        replyTo: $properties->replyTo,
        expiration: $expiration !== null && preg_match('/^\d+$/D', $expiration) === 1 ? ms((int) $expiration) : null,
        messageId: $properties->messageId,
        timestamp: $properties->timestamp === null ? null : new \DateTimeImmutable("@{$properties->timestamp}"),
        type: $properties->type,
        userId: $properties->userId,
        appId: $properties->appId,
    );
}
