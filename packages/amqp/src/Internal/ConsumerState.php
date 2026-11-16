<?php

declare(strict_types=1);

namespace Thesis\Amqp\Internal;

use Thesis\Amqp\Delivery;
use Thesis\Async\Channel;

/**
 * What a channel keeps of one of its consumers: the deliveries that wait to be taken and whether they need no ack.
 *
 * @internal
 */
final readonly class ConsumerState
{
    /**
     * @param Channel<Delivery> $deliveries unbounded: the reader never waits for a consumer,
     *     a prefetch of {@see \Thesis\Amqp\Channel::qos()} bounds what the server sends
     */
    public function __construct(
        public Channel $deliveries,
        public bool $noAck,
    ) {}
}
