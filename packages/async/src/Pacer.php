<?php

declare(strict_types=1);

namespace Thesis\Async;

use Thesis\Async\Internal\Loop;

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
     * The tick of the event loop that {@see $count} counts the calls of.
     */
    private int $tick = 0;

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
     */
    public function pace(): void
    {
        $this->countTick();

        if ($this->count >= $this->perTick && self::canYield()) {
            checkpoint();
            // Other callers of a shared pacer may have counted their calls in the new tick before this one:
            // the count is of the tick, not of this caller, and starts anew only if nobody has counted yet.
            $this->countTick();
        }

        ++$this->count;
    }

    /**
     * Outside a run of the event loop, and in its callbacks, nothing else runs that a yield would let in,
     * and nothing could resume it: an operation that does not have to wait just goes on there.
     */
    private static function canYield(): bool
    {
        $loop = Loop::get();

        return $loop->main !== null && \Fiber::getCurrent() !== $loop->host;
    }

    /**
     * Starts the count anew in a new tick. The numbers of ticks are unique among all the loops, so a pacer
     * shared by nested ones (`stdout()` of thesis/io in `virtualTime()`) counts the calls of each tick apart.
     */
    private function countTick(): void
    {
        $tick = Loop::get()->tick;

        if ($this->tick !== $tick) {
            $this->tick = $tick;
            $this->count = 0;
        }
    }
}
