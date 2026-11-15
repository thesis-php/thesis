<?php

declare(strict_types=1);

namespace Thesis\Amqp;

use Thesis\Amqp\Internal\Protocol\BasicAck;
use Thesis\Amqp\Internal\Protocol\BasicNack;
use Thesis\Amqp\Internal\Protocol\BasicReject;

/**
 * A message from a consumer or from {@see Channel::get()}.
 *
 * It is settled once: the first of {@see ack()}, {@see nack()} and {@see reject()} counts, the later ones do nothing.
 * So `nack()` on a failure and `ack()` in `finally` is safe. A delivery of a `noAck` consumer is settled already.
 * A delivery covered by an earlier `multiple` settlement is settled too, as the server sees it.
 *
 * @api
 */
final class Delivery
{
    /**
     * @internal
     * @param \Closure(BasicAck|BasicNack|BasicReject): void $settle
     * @param \Closure(Message, string): void $reply
     * @param non-negative-int $deliveryTag
     * @param ?string $consumerTag null for a delivery of {@see Channel::get()}
     * @param bool $isSettled true for a delivery with `noAck`
     */
    public function __construct(
        private readonly \Closure $settle,
        private readonly \Closure $reply,
        public readonly Message $message,
        public readonly string $exchange,
        public readonly string $routingKey,
        public readonly int $deliveryTag,
        public readonly bool $redelivered,
        public readonly ?string $consumerTag,
        public private(set) bool $isSettled,
    ) {}

    /**
     * @param bool $multiple every delivery of the channel up to this one, of any consumer of the channel
     * @throws AmqpException if the channel is closed: the server has requeued the message then
     */
    public function ack(bool $multiple = false): void
    {
        if ($this->settle()) {
            ($this->settle)(new BasicAck($this->deliveryTag, $multiple));
        }
    }

    /**
     * @param bool $multiple every delivery of the channel up to this one, of any consumer of the channel
     * @param bool $requeue false to drop the message or to dead-letter it
     * @throws AmqpException if the channel is closed: the server has requeued the message then
     */
    public function nack(bool $multiple = false, bool $requeue = true): void
    {
        if ($this->settle()) {
            ($this->settle)(new BasicNack($this->deliveryTag, $multiple, $requeue));
        }
    }

    /**
     * @param bool $requeue false to drop the message or to dead-letter it
     * @throws AmqpException if the channel is closed: the server has requeued the message then
     */
    public function reject(bool $requeue = true): void
    {
        if ($this->settle()) {
            ($this->settle)(new BasicReject($this->deliveryTag, $requeue));
        }
    }

    /**
     * Publishes $message to the `replyTo` of this one through the default exchange, with its `correlationId`:
     * the answer of an RPC server. Does not settle the delivery.
     *
     * @throws AmqpException
     * @throws \LogicException if this message has no `replyTo`
     */
    public function reply(Message $message): void
    {
        $replyTo = $this->message->replyTo ?? throw new \LogicException('The message has no replyTo to reply to.');

        ($this->reply)(clone($message, ['correlationId' => $this->message->correlationId]), $replyTo);
    }

    private function settle(): bool
    {
        if ($this->isSettled) {
            return false;
        }

        $this->isSettled = true;

        return true;
    }
}
