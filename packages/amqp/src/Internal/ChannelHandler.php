<?php

declare(strict_types=1);

namespace Thesis\Amqp\Internal;

use Thesis\Amqp\AmqpException;
use Thesis\Amqp\Internal\Protocol\Method;

/**
 * What the connection calls on a channel. Closures of private methods, so that the public Channel
 * shows nothing of it.
 *
 * @internal
 */
final readonly class ChannelHandler
{
    /**
     * @param \Closure(Method|ContentHeader|string|null): void $handle a frame of the channel
     * @param \Closure(AmqpException): void $terminate the connection is gone
     * @param \Closure(): void $drain the connection is about to close: cancel the consumers, await the confirms
     */
    public function __construct(
        public \Closure $handle,
        public \Closure $terminate,
        public \Closure $drain,
    ) {}
}
