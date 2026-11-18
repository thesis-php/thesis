<?php

declare(strict_types=1);

namespace Thesis\Async;

use Thesis\Async\Internal\ContinuationImpl;
use Thesis\Async\Internal\Loop;
use Time\Duration;
use function Thesis\Time\toNs;

/**
 * The waits on one stream, owned by whoever owns the stream, so that closing it can end them.
 *
 * Every wait goes through {@see suspend()}: it is cancelled by the task tree and leaves nothing in the event loop
 * behind. Call {@see close()} before closing the stream: the kernel forgets a closed stream, and a fiber
 * waiting for it would never wake up. close() ends every wait with false, and every later one
 * returns false at once, so the fibers waiting for the stream learn that it is gone.
 *
 * Any number of fibers may wait at once, for the same readiness too: each of them is woken.
 *
 * @api
 */
final class StreamWatcher
{
    public private(set) bool $isClosed = false;

    /**
     * @var array<int, array{ContinuationImpl<bool>, \Closure(): void}> each wait and what undoes it, by spl_object_id()
     */
    private array $waits = [];

    /**
     * @param resource $stream
     */
    public function __construct(
        private readonly mixed $stream,
    ) {}

    /**
     * Waits until a read would not block: there are bytes, the end of the stream, or an error.
     * Read before waiting: TLS keeps decrypted bytes where the event loop does not see them.
     *
     * @return bool true once readable, false if {@see close()} has ended the wait or was called before
     * @throws Cancelled
     */
    public function readable(): bool
    {
        return $this->watch(write: false);
    }

    /**
     * Waits until a write would not block: the buffer of the OS has room, or the connection has failed.
     *
     * @return bool true once writable, false if {@see close()} has ended the wait or was called before
     * @throws Cancelled
     */
    public function writable(): bool
    {
        return $this->watch(write: true);
    }

    /**
     * A sleep like the function `delay()`, which {@see close()} ends too: a backoff of the stream's owner
     * between attempts, which must not outlive the stream.
     *
     * @return bool true once the time has passed, false if {@see close()} has ended the wait or was called before
     * @throws Cancelled
     */
    public function delay(Duration $duration): bool
    {
        $nanoseconds = toNs($duration);

        return $this->wait(static function (Loop $loop, ContinuationImpl $continuation) use ($nanoseconds) {
            $timer = $loop->delay($nanoseconds, $continuation);

            return static function () use ($loop, $timer) {
                $loop->cancel($timer);
            };
        });
    }

    /**
     * Ends every wait with false and forgets it in the event loop; every later wait returns false at once.
     * Call it before closing the stream. Idempotent.
     */
    public function close(): void
    {
        $this->isClosed = true;
        $waits = $this->waits;
        $this->waits = [];

        foreach ($waits as [$continuation, $undo]) {
            $undo();
            $continuation->resume(false);
        }
    }

    private function watch(bool $write): bool
    {
        $stream = $this->stream;

        return $this->wait(static function (Loop $loop, ContinuationImpl $continuation) use ($stream, $write) {
            $loop->watch($stream, $continuation, $write);

            return static function () use ($loop, $stream, $continuation, $write) {
                $loop->unwatch($stream, $continuation, $write);
            };
        });
    }

    /**
     * @param \Closure(Loop, ContinuationImpl<bool>): (\Closure(): void) $register returns what undoes it
     */
    private function wait(\Closure $register): bool
    {
        // Like a receive() from a closed channel: no wait, so no cancellation check either.
        if ($this->isClosed) {
            return false;
        }

        /** @var bool */
        return suspend(function (Continuation $continuation) use ($register) {
            /** @var ContinuationImpl<bool> $continuation */
            $undo = $register(Loop::get(), $continuation);
            $id = spl_object_id($continuation);
            $this->waits[$id] = [$continuation, $undo];

            return function () use ($id, $undo) {
                $undo();
                unset($this->waits[$id]);
            };
        });
    }
}
