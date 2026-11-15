<?php

declare(strict_types=1);

namespace Thesis\Amqp;

/**
 * Some messages of {@see Channel::publishBatch()} were nacked or returned; the others are confirmed.
 *
 * @api
 */
final class PublishBatchFailed extends AmqpException
{
    /**
     * @param list<Publish> $nacked
     * @param list<MessageReturned> $returned
     */
    public function __construct(
        public readonly array $nacked,
        public readonly array $returned,
    ) {
        parent::__construct(\sprintf(
            'Failed to publish %d of the messages: %d nacked, %d returned.',
            \count($nacked) + \count($returned),
            \count($nacked),
            \count($returned),
        ));
    }
}
