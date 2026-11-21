<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * What {@see Channel::trySend()} did with the value, like Rust `TrySendError` or Kotlin `ChannelResult`.
 *
 * @api
 */
enum SendResult
{
    /**
     * Handed over to a receiver or buffered.
     */
    case Sent;

    /**
     * The channel did not deliver the value. No receiver was waiting, and the buffer was full or absent.
     */
    case Full;

    /**
     * The channel did not deliver the value because the channel was closed.
     */
    case Closed;
}
