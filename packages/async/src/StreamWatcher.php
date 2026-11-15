<?php

declare(strict_types=1);

namespace Thesis\Async;

use Revolt\EventLoop;
use Time\Duration;
use function Thesis\Time\toSecFloat;

/**
 * The waits on one stream, owned by whoever owns the stream, so that closing it can end them.
 *
 * Every wait goes through {@see suspend()}: it is cancelled by the task tree and leaves no Revolt callback behind.
 * Call {@see close()} before closing the stream: a Revolt watcher of a closed stream is an error,
 * and a fiber waiting for it would never wake up. close() ends every wait with false, and every later one
 * returns false at once, so the fibers waiting for the stream learn that it is gone.
 *
 * Any number of fibers may wait at once, for the same readiness too: each wait has a watcher of its own.
 *
 * @api
 */
final class StreamWatcher
{
    public private(set) bool $isClosed = false;

    /**
     * @var array<string, Continuation<bool>> by Revolt callback id
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
        $stream = $this->stream;

        return $this->wait(static fn(\Closure $callback) => EventLoop::onReadable($stream, $callback));
    }

    /**
     * Waits until a write would not block: the buffer of the OS has room, or the connection has failed.
     *
     * @return bool true once writable, false if {@see close()} has ended the wait or was called before
     * @throws Cancelled
     */
    public function writable(): bool
    {
        $stream = $this->stream;

        return $this->wait(static fn(\Closure $callback) => EventLoop::onWritable($stream, $callback));
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
        // Revolt rejects a negative delay.
        $seconds = max(0.0, toSecFloat($duration));

        return $this->wait(static fn(\Closure $callback) => EventLoop::delay($seconds, $callback));
    }

    /**
     * Ends every wait with false and cancels its watcher; every later wait returns false at once.
     * Call it before closing the stream. Idempotent.
     */
    public function close(): void
    {
        $this->isClosed = true;
        $waits = $this->waits;
        $this->waits = [];

        foreach ($waits as $callbackId => $continuation) {
            EventLoop::cancel($callbackId);
            $continuation->resume(false);
        }
    }

    /**
     * @param \Closure(\Closure(): void): string $watch
     */
    private function wait(\Closure $watch): bool
    {
        // Like a receive() from a closed channel: no wait, so no cancellation check either.
        if ($this->isClosed) {
            return false;
        }

        /** @var bool */
        return suspend(function (Continuation $continuation) use ($watch) {
            /** @var Continuation<bool> $continuation */
            $callbackId = $watch(static function () use ($continuation) {
                $continuation->resume(true);
            });
            $this->waits[$callbackId] = $continuation;

            return function () use ($callbackId) {
                EventLoop::cancel($callbackId);
                unset($this->waits[$callbackId]);
            };
        });
    }
}
