<?php

declare(strict_types=1);

namespace Thesis\Amqp\Internal;

use Thesis\Amqp\Internal\Protocol\BasicDeliver;
use Thesis\Amqp\Internal\Protocol\BasicGetOk;
use Thesis\Amqp\Internal\Protocol\BasicProperties;
use Thesis\Amqp\Internal\Protocol\BasicReturn;
use Thesis\Amqp\Internal\Protocol\ProtocolError;
use Thesis\Amqp\Message;
use Thesis\Amqp\ReplyCode;

/**
 * Assembles a message of a channel from its frames: a method (basic.deliver, basic.get-ok, basic.return),
 * a content header, then as many body frames as the header says.
 *
 * @internal
 */
final class Content
{
    /**
     * Whether a message is being assembled: no other method may come in the middle of it.
     */
    public bool $isStarted {
        get => $this->method !== null;
    }

    private null|BasicDeliver|BasicGetOk|BasicReturn $method = null;

    private ?BasicProperties $properties = null;

    private int $remaining = 0;

    private string $body = '';

    /**
     * @param int<1, 65535> $channel
     */
    public function __construct(
        private readonly int $channel,
    ) {}

    public function start(BasicDeliver|BasicGetOk|BasicReturn $method): void
    {
        $this->method = $method;
    }

    /**
     * @return ?array{BasicDeliver|BasicGetOk|BasicReturn, Message} the message if its body is empty
     * @throws ProtocolError
     */
    public function receiveHeader(ContentHeader $header): ?array
    {
        if ($this->method === null || $this->properties !== null) {
            throw new ProtocolError(
                replyCode: ReplyCode::UnexpectedFrame,
                message: "An unexpected content header on channel {$this->channel}.",
            );
        }

        $this->properties = $header->properties;
        $this->remaining = $header->bodySize;

        return $header->bodySize === 0 ? $this->complete() : null;
    }

    /**
     * @return ?array{BasicDeliver|BasicGetOk|BasicReturn, Message} the message if this is the last piece of its body
     * @throws ProtocolError
     */
    public function receiveBody(string $body): ?array
    {
        if ($this->properties === null) {
            throw new ProtocolError(
                replyCode: ReplyCode::UnexpectedFrame,
                message: "An unexpected content body on channel {$this->channel}.",
            );
        }

        $this->body .= $body;
        $this->remaining -= \strlen($body);

        if ($this->remaining < 0) {
            throw new ProtocolError(
                replyCode: ReplyCode::FrameError,
                message: "A content body on channel {$this->channel} exceeds its size.",
            );
        }

        return $this->remaining === 0 ? $this->complete() : null;
    }

    /**
     * @return array{BasicDeliver|BasicGetOk|BasicReturn, Message}
     */
    private function complete(): array
    {
        $method = $this->method;
        \assert($method !== null && $this->properties !== null);
        $message = message($this->body, $this->properties);
        $this->method = null;
        $this->properties = null;
        $this->body = '';

        return [$method, $message];
    }
}
