<?php

declare(strict_types=1);

namespace Thesis\Amqp;

/**
 * A message of {@see Channel::publishBatch()} with where to publish it.
 *
 * @api
 */
final readonly class Publish
{
    public function __construct(
        public Message $message,
        public string $exchange = '',
        public string $routingKey = '',
        public bool $mandatory = false,
    ) {}
}
