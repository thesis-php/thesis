<?php

declare(strict_types=1);

namespace Thesis\Async\VirtualTime;

use Revolt\EventLoop;
use Thesis\Async\Internal\Scope;
use Thesis\Async\Internal\VirtualClock;
use Thesis\Async\Internal\VirtualTimeDriver;

/**
 * Runs $fn on a virtual clock and on a fresh Revolt driver whose timers follow it. The clock starts at the current
 * `Thesis\Async\now()` rounded up to a whole second, so a time captured before `run()` stays valid in it,
 * and durations of whole seconds are exact.
 * The clock jumps to the next timer whenever nothing can run: `delay(3600)` is over at once, timers fire exactly
 * in the order of their deadlines, and a deadlock fails at once instead of hanging. CPU work takes no time:
 * see {@see advance()}. `Thesis\Async\now()` and deadlines follow the clock, whatever the driver
 * is decorated with meanwhile.
 *
 * Streams and signals stay real: they are left to $driver, by default the one `EventLoop\DriverFactory` creates
 * (`REVOLT_DRIVER` chooses it). $driver must be fresh and is owned by this call: it is not the current driver
 * and has no callbacks, so nothing from outside runs on virtual time, and it is dropped with whatever is left in it.
 * Must be called in {main}. Afterwards the previous driver and clock are restored, and whatever $fn
 * leaves in the loop is dropped together with the driver. Calls nest: an inner one has a clock of its own.
 * An object that keeps times, such as a rate limiter, must not be shared between two calls that run one after another,
 * or used after the call in the outer clock: each clock goes its own way, and such a time may be in the future of the other.
 *
 * @api
 * @template T
 * @param callable(): T $fn
 * @param ?EventLoop\Driver $driver the driver of streams and signals, decorated with virtual timers
 * @return T
 * @throws \LogicException outside of {main}, or if $driver is the current one, is running or has callbacks
 */
function run(callable $fn, ?EventLoop\Driver $driver = null): mixed
{
    // In a fiber, the driver it runs on cannot be stopped from inside: run() would spin forever.
    if (\Fiber::getCurrent() !== null) {
        throw new \LogicException('VirtualTime\run() must be called in {main}, not in a fiber.');
    }

    if ($driver === null) {
        $driver = new EventLoop\DriverFactory()->create();
    } elseif ($driver === EventLoop::getDriver() || $driver->isRunning() || $driver->getIdentifiers() !== []) {
        throw new \LogicException('VirtualTime\run() needs a fresh driver: not the current one, not running and with no callbacks.');
    }

    // After a wait in {main}, the loop fiber stays suspended in the middle of a tick, and Revolt refuses
    // to swap a running driver: let it finish the tick and stop. A callback left for a later tick stays there.
    // A callback that throws interrupts the tick, and Revolt throws out of run() with the driver still running:
    // finish the tick anyway, then rethrow the first error.
    $stop = static function (EventLoop\Driver $driver): void {
        $error = null;

        while ($driver->isRunning()) {
            $driver->stop();

            try {
                $driver->run();
            } catch (\Throwable $exception) {
                $error ??= $exception;
            }
        }

        if ($error !== null) {
            throw $error;
        }
    };

    $previousDriver = EventLoop::getDriver();
    $stop($previousDriver);
    $previousClock = Scope::$virtualClock;
    // Not zero: a time captured outside, a deadline or a rate limiter, stays in the past. Whole seconds, so that
    // whole-second durations on it are exact, and rounded up, so that the clock does not go back.
    $clock = new VirtualClock(ceil(Scope::now()));
    EventLoop::setDriver(new VirtualTimeDriver($clock, $driver));
    Scope::$virtualClock = $clock;

    try {
        return $fn();
    } finally {
        try {
            // The current one, not the one created above: $fn may have decorated it.
            // Still on this clock: the callbacks of the last tick are of this run.
            $stop(EventLoop::getDriver());
        } finally {
            Scope::$virtualClock = $previousClock;
            EventLoop::setDriver($previousDriver);
        }
    }
}

/**
 * Moves the clock of the current {@see run()} forward without yielding to the event loop, as CPU work
 * that takes $seconds would: a deadline passes, but its timer fires only at the next suspension.
 *
 * @api
 * @throws \ValueError if $seconds is negative (the clock is monotonic) or NAN
 * @throws \LogicException outside of {@see run()}
 */
function advance(float $seconds): void
{
    if (Scope::$virtualClock === null) {
        throw new \LogicException('There is no virtual clock to advance outside of VirtualTime\run().');
    }

    Scope::$virtualClock->advance($seconds);
}
