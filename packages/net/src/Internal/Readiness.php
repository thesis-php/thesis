<?php

declare(strict_types=1);

namespace Thesis\Net\Internal;

use Revolt\EventLoop;
use Thesis\Async\Cancelled;
use Thesis\Async\Continuation;
use function Thesis\Async\checkpoint;
use function Thesis\Async\suspend;

/**
 * The waits on one stream, so that closing it can end them: a Revolt watcher of a closed stream is an error,
 * and a fiber waiting for it would never wake up.
 *
 * @internal
 */
final class Readiness
{
    /**
     * Operations in one tick before {@see pace()} yields: enough to drain a burst without a wait for each,
     * few enough that a peer sending without a pause cannot hold the event loop.
     */
    private const int IMMEDIATE_PER_TICK = 16;

    private int $immediate = 0;

    /**
     * @var array<string, Continuation<bool>> by Revolt callback id
     */
    private array $waits = [];

    /**
     * Call it before an operation that may complete without a wait (a read, an accept): after
     * {@see IMMEDIATE_PER_TICK} of them in one tick, it yields to the event loop, so other tasks and timers run.
     * It yields before the operation, so a cancelled one has done nothing.
     *
     * @throws Cancelled
     */
    public function pace(): void
    {
        if ($this->immediate >= self::IMMEDIATE_PER_TICK) {
            checkpoint();
            $this->immediate = 0;
        }

        if ($this->immediate++ === 0) {
            EventLoop::defer(function () {
                $this->immediate = 0;
            });
        }
    }

    /**
     * @param resource $stream
     * @return bool true once readable, false if {@see close()} ended the wait
     */
    public function readable(mixed $stream): bool
    {
        return $this->wait(static fn(\Closure $callback) => EventLoop::onReadable($stream, $callback));
    }

    /**
     * @param resource $stream
     * @return bool true once writable, false if {@see close()} ended the wait
     */
    public function writable(mixed $stream): bool
    {
        return $this->wait(static fn(\Closure $callback) => EventLoop::onWritable($stream, $callback));
    }

    /**
     * @return bool true once the time has passed, false if {@see close()} ended the wait
     */
    public function sleep(float $seconds): bool
    {
        return $this->wait(static fn(\Closure $callback) => EventLoop::delay($seconds, $callback));
    }

    /**
     * Ends every wait with false and cancels its watcher. Call it before closing the stream.
     */
    public function close(): void
    {
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
