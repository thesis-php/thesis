<?php

declare(strict_types=1);

namespace Thesis\Async;

use Thesis\Async\Internal\WaitQueue;

/**
 * A bounded queue of values from any number of senders to any number of receivers, with backpressure:
 * {@see send()} waits while the buffer is full, {@see receive()} waits while it is empty.
 * With capacity 0 it is a rendezvous: send() waits until a receiver takes the value.
 *
 * Values and waiters are FIFO. A value is handed over to the first waiting receiver directly,
 * like a permit of {@see LocalSemaphore}: a receiver that has been cancelled in the meantime refuses it
 * ({@see Continuation::resume()} returns false), and it goes to the next one or to the buffer.
 * The same with a waiting sender: its value is taken only if its resume() wins. So a send() that throws
 * {@see Cancelled} has not delivered its value, and a receive() that throws {@see Cancelled} has not lost one.
 *
 * The sender closes the channel: after {@see close()}, send() throws {@see ChannelClosed}, waiting senders are woken
 * with it and their values are not delivered, and receivers get what is left in the buffer before they see it.
 * A producer that failed closes it with the reason: receivers get the
 * reason instead of the end, once the buffer is drained.
 *
 * send() and receive() that do not have to wait return immediately, without a cancellation check,
 * like {@see Deferred::await()} of a done deferred. {@see trySend()} and {@see tryReceive()} never wait and never
 * throw: trySend() returns {@see SendResult::Closed} on a closed channel, and tryReceive() returns its default
 * on a closed and drained one, so that the next receive() reports the end.
 *
 * Like Go `len(ch)` and `cap(ch)`, `count($channel)` tells how many values are in the buffer and {@see $capacity} how
 * many fit: for metrics and for deciding whether to shed load. Values of waiting
 * senders are not counted, so a rendezvous always has 0.
 *
 * Do not wait for several channels with {@see race()}: two receive() calls may get values in the same tick,
 * and race() keeps only one of them.
 *
 * @api
 * @template T
 * @implements \IteratorAggregate<int, T>
 */
final class Channel implements \IteratorAggregate, \Countable
{
    /**
     * @var \SplQueue<T>
     */
    private readonly \SplQueue $buffer;

    /**
     * @var WaitQueue<Continuation<?array{T}>> resumed with null once the channel is closed
     */
    private readonly WaitQueue $receivers;

    /**
     * @var WaitQueue<array{Continuation<null>, T}>
     */
    private readonly WaitQueue $senders;

    public private(set) bool $isClosed = false;

    private ?\Throwable $reason = null;

    /**
     * @param non-negative-int $capacity how many values may wait in the buffer, 0 for a rendezvous.
     *                                   There is no default: how much a slow receiver may fall behind is a decision.
     */
    public function __construct(
        public readonly int $capacity,
    ) {
        $this->buffer = new \SplQueue();
        $this->receivers = new WaitQueue();
        $this->senders = new WaitQueue();
    }

    /**
     * Waits until there is a receiver or room in the buffer.
     *
     * @param T $value
     * @throws ChannelClosed if the channel is closed before or while waiting: then the value is not delivered.
     *                       The reason of {@see close()} is its previous exception.
     * @throws Cancelled if the scope is cancelled while waiting: then the value is not delivered
     */
    public function send(mixed $value): void
    {
        if ($this->isClosed) {
            throw new ChannelClosed($this->reason);
        }

        if ($this->offer($value)) {
            return;
        }

        suspend(function (Continuation $continuation) use ($value) {
            /** @var Continuation<null> $continuation */
            $key = $this->senders->push([$continuation, $value]);

            return function () use ($key) {
                $this->senders->remove($key);
            };
        });
        // Resumed by a receiver: the value has been taken.
    }

    /**
     * Does not wait and never throws: for values that are better dropped than delayed, such as metrics.
     *
     * Unlike {@see send()}, it reports a closed channel instead of throwing {@see ChannelClosed}:
     * a dropped value is dropped whatever the reason.
     *
     * @param T $value
     */
    public function trySend(mixed $value): SendResult
    {
        if ($this->isClosed) {
            return SendResult::Closed;
        }

        return $this->offer($value) ? SendResult::Sent : SendResult::Full;
    }

    /**
     * @param T $value
     * @return bool whether the value was handed over to a receiver or buffered
     */
    private function offer(mixed $value): bool
    {
        // A waiting receiver means the buffer is empty: a value goes to the
        // buffer only when there is no one to hand it to.
        while ($this->receivers->items !== [] && ($receiver = $this->receivers->shift()) !== null) {
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
     * @throws ChannelClosed if the channel was closed without a reason and no values remain
     * @throws \Throwable if the channel closed with an exception and no values remain. Throws the same exception.
     * @throws Cancelled if the scope is cancelled while waiting. The receive consumes no value.
     */
    public function receive(): mixed
    {
        $value = $this->take() ?? $this->wait();

        if ($value === null) {
            throw $this->reason ?? new ChannelClosed();
        }

        return $value[0];
    }

    /**
     * Does not wait and never throws: for taking what has piled up without delaying the first value, such as a batch.
     *
     * It returns $default whenever there is no value right now, closed or not: the end, or the reason of
     * {@see close()}, is reported by the next {@see receive()} or `foreach`, so a batch taken before it is not lost.
     *
     * If a value may be null, pass a $default that is not one of them to tell "nothing" apart:
     * `$value = $channel->tryReceive($none = new \stdClass())`, then compare with `=== $none`.
     *
     * @template D
     * @param D $default returned if there is no value right now
     * @return T|D
     */
    public function tryReceive(mixed $default = null): mixed
    {
        $value = $this->take();

        if ($value !== null) {
            return $value[0];
        }

        return $default;
    }

    /**
     * Wakes every waiter: senders get {@see ChannelClosed}, receivers get the end. Values in the buffer stay to be
     * received. Only the first call counts: a later one is ignored, its reason too.
     *
     * @param ?\Throwable $reason why there is nothing more: receivers get it instead of the end once the buffer
     *     is drained, the same object every time, as awaiters of a failed {@see Deferred} do
     * @throws \ValueError if $reason is {@see Cancelled}: it would be thrown in receivers whose scope is not cancelled
     */
    public function close(?\Throwable $reason = null): void
    {
        if ($reason instanceof Cancelled) {
            throw new \ValueError(
                message: 'A channel cannot be closed with Cancelled: its receivers are not cancelled. Close it with a regular exception.',
            );
        }

        if ($this->isClosed) {
            return;
        }

        $this->isClosed = true;
        $this->reason = $reason;

        foreach ($this->senders->drain() as [$sender]) {
            $sender->throw(new ChannelClosed($reason));
        }

        // A waiting receiver means the buffer is empty, so there is nothing left for it.
        foreach ($this->receivers->drain() as $receiver) {
            $receiver->resume(null);
        }
    }

    /**
     * How many values are in the buffer, at most {@see $capacity}. Values of waiting senders are not counted.
     *
     * @return non-negative-int
     */
    public function count(): int
    {
        return $this->buffer->count();
    }

    /**
     * Yields values until the channel is closed and the buffer is empty.
     *
     * @return \Generator<int, T, void, void>
     * @throws Cancelled if the scope is cancelled while waiting
     * @throws \Throwable if the channel closed with an exception and no values remain. Throws the same exception.
     */
    public function getIterator(): \Generator
    {
        while (($value = $this->take() ?? $this->wait()) !== null) {
            yield $value[0];
        }

        if ($this->reason !== null) {
            throw $this->reason;
        }
    }

    /**
     * @return ?array{T} null if there is nothing to take right now
     */
    private function take(): ?array
    {
        if (!$this->buffer->isEmpty()) {
            $value = $this->buffer->dequeue();

            // There is room now: the first waiting sender moves its value to the buffer and goes on.
            while ($this->senders->items !== [] && ($waiting = $this->senders->shift()) !== null) {
                [$sender, $sent] = $waiting;

                if ($sender->resume()) {
                    $this->buffer->enqueue($sent);

                    break;
                }
            }

            return [$value];
        }

        // An empty buffer with waiting senders is a rendezvous: the value is taken from the sender directly.
        while ($this->senders->items !== [] && ($waiting = $this->senders->shift()) !== null) {
            [$sender, $sent] = $waiting;

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
        if ($this->isClosed) {
            return null;
        }

        /** @var ?array{T} */
        return suspend(function (Continuation $continuation) {
            /** @var Continuation<?array{T}> $continuation */
            $key = $this->receivers->push($continuation);

            return function () use ($key) {
                $this->receivers->remove($key);
            };
        });
    }
}
