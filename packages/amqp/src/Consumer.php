<?php

declare(strict_types=1);

namespace Thesis\Amqp;

use Thesis\Async\Channel as AsyncChannel;
use Thesis\Async\ChannelClosed as AsyncChannelClosed;
use Time\Duration;
use function Thesis\Async\timeoutOr;

/**
 * The deliveries of a consumer, started by {@see Channel::consume()}. Iterate it, in one fiber or in many:
 *
 *     each($consumer, concurrency: 10, static function (Delivery $delivery) { ...; $delivery->ack(); });
 *
 * The iteration ends once the consumer is cancelled ({@see cancel()}, {@see Channel::close()},
 * {@see Client::close()}) and the deliveries that arrived before are taken. It throws {@see ConsumerCancelled}
 * if the server has cancelled the consumer, {@see ChannelClosed} or {@see ConnectionClosed} if the channel is gone.
 *
 * @api
 * @implements \IteratorAggregate<int, Delivery>
 */
final readonly class Consumer implements \IteratorAggregate
{
    /**
     * @internal
     * @param AsyncChannel<Delivery> $deliveries
     * @param \Closure(): void $cancel
     */
    public function __construct(
        public string $tag,
        private AsyncChannel $deliveries,
        private \Closure $cancel,
    ) {}

    /**
     * Stops the deliveries: once the server has confirmed it, the iteration ends after what has arrived.
     * Idempotent.
     */
    public function cancel(): void
    {
        ($this->cancel)();
    }

    /**
     * @return \Generator<int, Delivery>
     */
    public function getIterator(): \Generator
    {
        yield from $this->deliveries;
    }

    /**
     * Groups the deliveries: a batch is yielded once it has $count deliveries or, when it has at least one,
     * once $timeout has passed since the first delivery. Acknowledge it as a whole with {@see Batch::ack()}.
     * Batch iteration ends or throws for the same reasons as delivery iteration.
     *
     * A batch is acknowledged with `multiple`, which covers every consumer of the channel: give the consumer
     * a channel of its own, and a prefetch of at least $count ({@see Channel::qos()}), or no batch fills up.
     *
     * @param positive-int $count
     * @return \Generator<int, Batch>
     */
    public function batches(int $count, Duration $timeout): \Generator
    {
        while (true) {
            try {
                $deliveries = [$this->deliveries->receive()];
            } catch (AsyncChannelClosed) {
                return;
            }

            timeoutOr($timeout, function () use (&$deliveries, $count) {
                try {
                    while (\count($deliveries) < $count) {
                        $deliveries[] = $this->deliveries->receive();
                    }
                } catch (AsyncChannelClosed|AmqpException) {
                    // The next receive() above ends the iteration or throws the reason, after this batch.
                }
            });

            yield new Batch($deliveries);
        }
    }
}
