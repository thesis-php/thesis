<?php

declare(strict_types=1);

namespace Thesis\Async;

use Io\Poll\Event;
use Thesis\Async\Internal\Loop;
use Thesis\Async\Internal\Waiter;

/**
 * Runs a non-blocking callback on stream readiness. Returning false pauses observation until recheck().
 *
 * @api
 */
final class ReadObserver implements Waiter
{
    private bool $watching = false;

    private bool $queued = false;

    /**
     * @internal
     * @param resource $stream
     * @param Event::Read|Event::ReadHangUp $event
     * @param \Closure(self): bool $probe
     */
    public function __construct(
        private readonly mixed $stream,
        private readonly Loop $loop,
        private readonly Event $event,
        private ?\Closure $probe,
    ) {
        if ($event === Event::Read) {
            // The PHP stream may already have buffered input that the kernel cannot report.
            $this->recheck();
        } else {
            $this->watching = true;
            $loop->watch($stream, $this, $event);
        }
    }

    /**
     * Run a paused probe after the current fiber yields. Repeated requests share one callback.
     */
    public function recheck(): void
    {
        if (!$this->watching && !$this->queued && $this->probe !== null) {
            $this->queued = true;
            $this->loop->defer($this);
        }
    }

    /**
     * @internal
     */
    #[\Override]
    public function wake(): void
    {
        $this->queued = false;
        $this->watching = false;
        $probe = $this->probe;
        if ($probe === null || !$probe($this)) {
            return;
        }

        // The callback can close the owner or request a deferred check.
        if ($this->probe !== null && !$this->queued) {
            $this->watching = true;
            $this->loop->watch($this->stream, $this, $this->event);
        }
    }

    public function close(): void
    {
        $this->probe = null;
        if ($this->watching) {
            $this->loop->unwatch($this->stream, $this, $this->event);
            $this->watching = false;
        }
        if ($this->queued) {
            $this->loop->undefer($this);
            $this->queued = false;
        }
    }
}
