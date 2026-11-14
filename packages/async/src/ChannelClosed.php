<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * Thrown by {@see Channel::send()} and {@see Channel::trySend()} once the channel is closed,
 * and by {@see Channel::receive()} and {@see Channel::tryReceive()} once it is closed and every buffered value has been received.
 *
 * It is a regular exception: closing is how a sender says there is nothing more, not a failure or a cancellation.
 * A channel closed with a reason gives that reason to its receivers instead, and to its senders as the previous exception.
 *
 * @api
 */
final class ChannelClosed extends \RuntimeException
{
    /**
     * @internal
     */
    public function __construct(?\Throwable $reason = null)
    {
        parent::__construct('The channel is closed.', previous: $reason);
    }
}
