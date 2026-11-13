<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * Thrown by {@see Channel::send()} and {@see Channel::trySend()} once the channel is closed,
 * and by {@see Channel::receive()} once it is closed and every buffered value has been received.
 *
 * It is a regular exception: closing is how a sender says there is nothing more, not a failure or a cancellation.
 *
 * @api
 */
final class ChannelClosed extends \RuntimeException
{
    /**
     * @internal
     */
    public function __construct()
    {
        parent::__construct('The channel is closed.');
    }
}
