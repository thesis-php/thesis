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
     * Not delivered: no receiver is waiting and the buffer is full (or the channel is a rendezvous).
     */
    case Full;

    /**
     * Not delivered: the channel is closed.
     */
    case Closed;
}
