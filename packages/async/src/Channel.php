<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * A bounded queue of values from any number of senders to any number of receivers, with backpressure:
 * {@see send()} waits while the buffer is full, {@see receive()} waits while it is empty.
 * With capacity 0 it is a rendezvous: send() waits until a receiver takes the value.
 *
 * Values and waiters are FIFO. A value is handed over to the first waiting receiver directly,
 * like a permit of {@see LocalSemaphore}: a receiver that has been cancelled in the meantime refuses it
 * ({@see Continuation::resume()} returns false), and it goes to the next one or to the buffer.
 * The same with a waiting sender: its value is taken only if its resume() wins. So a send() that throws {@see Cancelled}
 * has not delivered its value, and a receive() that throws {@see Cancelled} has not lost one.
 *
 * The sender closes the channel: after {@see close()}, send() throws {@see ChannelClosed}, waiting senders are woken
 * with it and their values are not delivered, and receivers get what is left in the buffer before they see it.
 *
 * send() and receive() that do not have to wait return immediately, without a cancellation check,
 * like {@see Deferred::await()} of a done deferred.
 *
 * Do not wait for several channels with {@see race()}: two receive() calls may get values in the same tick,
 * and race() keeps only one of them.
 *
 * @api
 * @template T
 * @implements \IteratorAggregate<int, T>
 */
final class Channel implements \IteratorAggregate
{
    /** @var \SplQueue<T> */
    private readonly \SplQueue $buffer;

    /** @var array<int, Continuation<?array{T}>> in the order of arrival; resumed with null once the channel is closed */
    private array $receivers = [];

    /** @var array<int, array{Continuation<null>, T}> in the order of arrival */
    private array $senders = [];

    private bool $closed = false;

    /**
     * @param non-negative-int $capacity how many values may wait in the buffer, 0 for a rendezvous.
     *                                   There is no default: how much a slow receiver may fall behind is a decision.
     */
    public function __construct(
        private readonly int $capacity,
    ) {
        $this->buffer = new \SplQueue();
    }

    /**
     * Waits until there is a receiver or room in the buffer.
     *
     * @param T $value
     * @throws ChannelClosed if the channel is closed before or while waiting: then the value is not delivered
     * @throws Cancelled if the scope is cancelled while waiting: then the value is not delivered
     */
    public function send(mixed $value): void
    {
        if ($this->trySend($value)) {
            return;
        }

        suspend(function (Continuation $continuation) use ($value) {
            /** @var Continuation<null> $continuation */
            $id = spl_object_id($continuation);
            $this->senders[$id] = [$continuation, $value];

            return function () use ($id) {
                unset($this->senders[$id]);
            };
        });
        // Resumed by a receiver: the value has been taken.
    }

    /**
     * Does not wait: for values that are better dropped than delayed, such as metrics.
     *
     * @param T $value
     * @return bool whether the value was handed over to a receiver or buffered, false if there is no room
     * @throws ChannelClosed if the channel is closed
     */
    public function trySend(mixed $value): bool
    {
        if ($this->closed) {
            throw new ChannelClosed();
        }

        // A waiting receiver means the buffer is empty: a value goes to the buffer only when there is no one to hand it to.
        foreach ($this->receivers as $id => $receiver) {
            unset($this->receivers[$id]);

            if ($receiver->resume([$value])) {
                return true;
            }
        }

        if ($this->buffer->count() < $this->capacity) {
            $this->buffer->enqueue($value);

            return true;
        }

        return false;
    }

    /**
     * Waits until there is a value.
     *
     * @return T
     * @throws ChannelClosed if the channel is closed and there are no values left
     * @throws Cancelled if the scope is cancelled while waiting: then no value has been taken
     */
    public function receive(): mixed
    {
        $value = $this->tryReceive() ?? $this->wait();

        if ($value === null) {
            throw new ChannelClosed();
        }

        return $value[0];
    }

    /**
     * Wakes every waiter: senders and receivers get {@see ChannelClosed}. Values in the buffer stay to be received.
     * Idempotent.
     */
    public function close(): void
    {
        // Idempotent without a check: a second call finds no waiters to wake.
        $this->closed = true;

        $senders = $this->senders;
        $this->senders = [];

        foreach ($senders as [$sender]) {
            $sender->throw(new ChannelClosed());
        }

        // A waiting receiver means the buffer is empty, so there is nothing left for it.
        $receivers = $this->receivers;
        $this->receivers = [];

        foreach ($receivers as $receiver) {
            $receiver->resume(null);
        }
    }

    /**
     * Receives until the channel is closed and drained, then ends normally.
     *
     * @return \Generator<int, T, void, void>
     * @throws Cancelled if the scope is cancelled while waiting
     */
    public function getIterator(): \Generator
    {
        while (($value = $this->tryReceive() ?? $this->wait()) !== null) {
            yield $value[0];
        }
    }

    /**
     * @return ?array{T} null if there is nothing to take right now
     */
    private function tryReceive(): ?array
    {
        if (!$this->buffer->isEmpty()) {
            $value = $this->buffer->dequeue();

            // There is room now: the first waiting sender moves its value to the buffer and goes on.
            foreach ($this->senders as $id => [$sender, $sent]) {
                unset($this->senders[$id]);

                if ($sender->resume()) {
                    $this->buffer->enqueue($sent);

                    break;
                }
            }

            return [$value];
        }

        // An empty buffer with waiting senders is a rendezvous: the value is taken from the sender directly.
        foreach ($this->senders as $id => [$sender, $sent]) {
            unset($this->senders[$id]);

            if ($sender->resume()) {
                return [$sent];
            }
        }

        return null;
    }

    /**
     * @return ?array{T} null if the channel is closed
     * @throws Cancelled
     */
    private function wait(): ?array
    {
        if ($this->closed) {
            return null;
        }

        /** @var ?array{T} */
        return suspend(function (Continuation $continuation) {
            /** @var Continuation<?array{T}> $continuation */
            $id = spl_object_id($continuation);
            $this->receivers[$id] = $continuation;

            return function () use ($id) {
                unset($this->receivers[$id]);
            };
        });
    }
}
