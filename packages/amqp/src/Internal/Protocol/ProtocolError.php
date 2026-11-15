<?php

declare(strict_types=1);

namespace Thesis\Amqp\Internal\Protocol;

use Thesis\Amqp\ReplyCode;

/**
 * The peer has sent what the protocol does not allow: the connection cannot go on.
 *
 * @internal
 */
final class ProtocolError extends \RuntimeException
{
    public static function unknownMethod(int $classId, int $methodId): self
    {
        return new self(ReplyCode::CommandInvalid, "Unknown method {$classId}.{$methodId}.");
    }

    public function __construct(
        public readonly ReplyCode $replyCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
