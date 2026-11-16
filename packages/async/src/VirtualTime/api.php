<?php

declare(strict_types=1);

namespace Thesis\Async\VirtualTime;

use Revolt\EventLoop;
use Thesis\Async\Internal\Scope;
use Thesis\Async\Internal\VirtualClock;
use Thesis\Async\Internal\VirtualTimeDriver;
use Time\Duration;
use function Thesis\Time\toNs;

/**
 * Runs $fn on a virtual clock and on a fresh Revolt driver whose timers follow it. The clock starts at the current
 * `Thesis\Async\Instant::now()` rounded up to a whole second, so a time captured before `run()` stays valid in it.
 * The clock jumps to the next timer whenever nothing can run: `delay(hours(1))` is over at once, timers fire exactly
 * in the order of their deadlines, and a deadlock fails at once instead of hanging. CPU work takes no time:
 * see {@see advance()}. `Thesis\Async\Instant::now()` and deadlines follow the clock, whatever the driver
 * is decorated with meanwhile.
 *
 * Streams and signals stay real: they are left to $driver, by default the one `EventLoop\DriverFactory` creates
 * (`REVOLT_DRIVER` chooses it). $driver must be fresh and is owned by this call: it is not the current driver
 * and has no callbacks, so nothing from outside runs on virtual time, and it is dropped with whatever is left in it.
 * Must be called in {main}. Afterwards the previous driver and clock are restored, and whatever $fn
 * leaves in the loop is dropped together with the driver. Calls nest: an inner one has a clock of its own.
 * An object that keeps times, such as a rate limiter, must not be shared between two calls that run one after another,
 * or used after the call in the outer clock: each clock goes its own way,
 * and such a time may be in the future of the other.
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
        throw new \LogicException(
            message: 'VirtualTime\run() needs a fresh driver: not the current one, not running and with no callbacks.',
        );
    }

    // After a wait in {main}, the loop fiber stays suspended in the middle of a tick, and Revolt refuses
    // to swap a running driver: let it finish the tick and stop. A callback left for a later tick stays there.
    // A callback that throws interrupts the tick, and Revolt throws out of run() with the driver still running:
    // finish the tick anyway, then rethrow the first error.
    $stop = static function (EventLoop\Driver $driver) {
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
    // Not zero: a time captured outside, a deadline or a rate limiter, stays in the past. Rounded up, so that
    // the clock does not go back, to a whole second, so that the times of a dump read well. Not rounded
    // at the end of time (an outer advance() of centuries): the rounding would overflow.
    $now = Scope::now();
    $start = $now <= PHP_INT_MAX - 999_999_999 ? intdiv($now + 999_999_999, 1_000_000_000) * 1_000_000_000 : $now;
    $clock = new VirtualClock($start);
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
 * that takes $duration would: a deadline passes, but its timer fires only at the next suspension.
 *
 * @api
 * @throws \ValueError if $duration is negative: the clock is monotonic
 * @throws \LogicException outside of {@see run()}
 */
function advance(Duration $duration): void
{
    if (Scope::$virtualClock === null) {
        throw new \LogicException('There is no virtual clock to advance outside of VirtualTime\run().');
    }

    Scope::$virtualClock->advance(toNs($duration));
}
