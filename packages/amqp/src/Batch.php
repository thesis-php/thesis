<?php

declare(strict_types=1);

namespace Thesis\Amqp;

/**
 * Deliveries of {@see Consumer::batches()}, settled at once with `multiple` on the last one.
 *
 * @api
 * @implements \IteratorAggregate<int, Delivery>
 */
final readonly class Batch implements \IteratorAggregate, \Countable
{
    /**
     * @param non-empty-list<Delivery> $deliveries in the order of delivery
     */
    public function __construct(
        public array $deliveries,
    ) {}

    /**
     * @throws AmqpException
     */
    public function ack(): void
    {
        $this->last()->ack(multiple: true);
    }

    /**
     * @throws AmqpException
     */
    public function nack(bool $requeue = true): void
    {
        $this->last()->nack(multiple: true, requeue: $requeue);
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

    private function last(): Delivery
    {
        return array_last($this->deliveries);
    }
}
