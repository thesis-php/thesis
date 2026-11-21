<?php

declare(strict_types=1);

namespace Thesis\Amqp;

/**
 * {@see Consumer::batches()} groups deliveries into batches. Each batch settles all deliveries at once
 * with `multiple` on its last delivery.
 *
 * @api
 * @implements \IteratorAggregate<int, Delivery>
 */
final class Batch implements \IteratorAggregate, \Countable
{
    private Delivery $last {
        get => array_last($this->deliveries);
    }

    /**
     * @param non-empty-list<Delivery> $deliveries in the order of delivery
     */
    public function __construct(
        public readonly array $deliveries,
    ) {}

    /**
     * @throws AmqpException
     */
    public function ack(): void
    {
        $this->last->ack(multiple: true);
    }

    /**
     * @throws AmqpException
     */
    public function nack(bool $requeue = true): void
    {
        $this->last->nack(multiple: true, requeue: $requeue);
    }

    /**
     * @return \ArrayIterator<int, Delivery>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->deliveries);
    }

    /**
     * @return positive-int
     */
    public function count(): int
    {
        return \count($this->deliveries);
    }
}
