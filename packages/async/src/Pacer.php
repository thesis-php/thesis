<?php

declare(strict_types=1);

namespace Thesis\Async;

use Revolt\EventLoop;

/**
 * Fairness for an operation that may complete without a wait: a read that finds bytes, an accept that finds
 * a connection, a receive from a full channel. Code that never has to wait never yields to the event loop,
 * so a peer that sends without a pause would starve the other tasks and the timers, {@see timeout()} included.
 *
 * Call {@see pace()} before every such operation: after $perTick calls within one tick of the event loop,
 * it yields for one tick through {@see checkpoint()}. The count starts anew every tick, so an operation that waits
 * now and then never pays for a yield. Unlike `checkpoint()` every N iterations, it counts the calls,
 * not the iterations of one loop: keep one pacer per source of work (a socket, a server), shared by its callers.
 *
 * @api
 */
final class Pacer
{
    private int $count = 0;

    /**
     * Counts the resets of {@see $count}: a yield that has seen none, has lost its tick with the event loop driver.
     */
    private int $resets = 0;

    /**
     * @param positive-int $perTick calls without a yield: enough to drain a burst without a wait for each,
     *                              few enough that one busy source cannot hold the event loop
     */
    public function __construct(
        private readonly int $perTick,
    ) {}

    /**
     * Yields before the operation, so if it throws {@see Cancelled}, the operation has not happened.
     * A cancellation point only when it yields: the other calls only count.
     *
     * @throws Cancelled
     */
    public function pace(): void
    {
        if ($this->count >= $this->perTick) {
            $resets = $this->resets;
            checkpoint();

            // The defer of the tick has reset the count before the yield returns, and since then other callers
            // of a shared pacer may have counted their calls in the new tick: a reset here would erase them.
            // Unless the defer was dropped with its driver (the end of `VirtualTime\run()`, a new driver):
            // then nothing would ever reset the count again.
            if ($this->resets === $resets) {
                $this->count = 0;
            }
        }

        if ($this->count === 0) {
            EventLoop::defer(function () {
                $this->count = 0;
                ++$this->resets;
            });
        }

        ++$this->count;
    }
}
