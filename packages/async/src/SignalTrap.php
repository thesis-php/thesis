<?php

declare(strict_types=1);

namespace Thesis\Async;

/**
 * The signals caught by {@see trapSignals()} while its callable runs.
 *
 * Not a queue: like the OS, it keeps at most one pending signal per number, so a signal that comes again
 * before it is received is merged with the pending one, and never pushes out a different signal.
 * Pending signals are received in the order they first came.
 *
 * @api
 * @extends \IteratorAggregate<int, int>
 * @phpstan-sealed Internal\SignalTrapImpl
 */
interface SignalTrap extends \IteratorAggregate
{
    /**
     * Waits until a signal is caught.
     *
     * @throws \LogicException if {@see trapSignals()} has returned and nothing is pending
     * @throws Cancelled if the scope is cancelled while waiting: then no signal has been taken
     */
    public function receive(): int;

    /**
     * Receives the signals as they come, and ends once {@see trapSignals()} has returned and nothing is pending.
     *
     * @return \Traversable<int, int>
     */
    public function getIterator(): \Traversable;
}
