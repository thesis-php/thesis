<?php

declare(strict_types=1);

namespace Thesis\Amqp;

/**
 * The channel is closed: a method failed on the server (`NotFound`, `PreconditionFailed`, `AccessRefused`),
 * or the channel was closed by {@see Channel::close()} (`ReplySuccess`). Open a new channel to go on.
 * The reply code is null if the server sent one this version does not know.
 *
 * @api
 */
final class ChannelClosed extends AmqpException
{
    /**
     * @param int $classId the class of the method that failed, 0 if none
     * @param int $methodId the method that failed, 0 if none
     */
    public function __construct(
        string $message,
        public readonly ?ReplyCode $replyCode,
        public readonly int $classId = 0,
        public readonly int $methodId = 0,
    ) {
        parent::__construct($message);
    }
}
