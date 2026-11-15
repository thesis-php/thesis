<?php

declare(strict_types=1);

namespace Thesis\Amqp;

use Time\Duration;

/**
 * A message with its properties. A null property is absent from the content header, an empty string is present:
 * a message decodes as it was encoded.
 *
 * The properties are `public(set)`, so that a copy with some of them changed is `clone($message, ['priority' => 5])`.
 *
 * @api
 */
final readonly class Message
{
    /**
     * @param array<string, mixed> $headers values of a field table: bool, int, float, string, null,
     *     \DateTimeInterface, a list or an array of them. A decimal arrives as a numeric string
     * @param ?int<0, 255> $priority RabbitMQ honours it only in a queue with `x-max-priority`, up to 255
     * @param ?Duration $expiration the TTL of the message, sent in whole milliseconds rounded up
     * @param ?string $userId RabbitMQ rejects a message whose user id is not the user of the connection
     */
    public function __construct(
        public public(set) string $body = '',
        public public(set) array $headers = [],
        public public(set) ?string $contentType = null,
        public public(set) ?string $contentEncoding = null,
        public public(set) ?DeliveryMode $deliveryMode = null,
        public public(set) ?int $priority = null,
        public public(set) ?string $correlationId = null,
        public public(set) ?string $replyTo = null,
        public public(set) ?Duration $expiration = null,
        public public(set) ?string $messageId = null,
        public public(set) ?\DateTimeImmutable $timestamp = null,
        public public(set) ?string $type = null,
        public public(set) ?string $userId = null,
        public public(set) ?string $appId = null,
    ) {}
}
