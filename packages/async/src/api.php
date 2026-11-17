<?php

declare(strict_types=1);

namespace Thesis\Async;

use Amp\Cancellation;
use Amp\CancelledException;
use Revolt\EventLoop;
use Thesis\Async\Internal\ContinuationImpl;
use Thesis\Async\Internal\Expired;
use Thesis\Async\Internal\GroupImpl;
use Thesis\Async\Internal\Scope;
use Thesis\Async\Internal\ScopeCancellation;
use Thesis\Async\Internal\SignalTrapImpl;
use Thesis\Async\Internal\Suppressed;
use Thesis\Async\Internal\VirtualClock;
use Thesis\Async\Internal\VirtualTimeDriver;
use Thesis\Async\Internal\Workers;
use Time\Duration;
use function Thesis\Async\Internal\fiberKey;
use function Thesis\Time\ns;
use function Thesis\Time\sec;
use function Thesis\Time\toNs;

/**
 * THE suspension point: every wait in the library goes through it, so cancellation is implemented once.
 *
 * $register receives the {@see Continuation} of this wait and registers it wherever the event comes from
 * (timer, stream watcher, wait queue). It may return $unregister, a closure that undoes the registration.
 * $unregister is always called once the wait is over, whatever ended it,
 * so it must be harmless for a source that has already fired. Anything but a closure is ignored:
 * not even a callable string is called, since a Revolt callback id may be one ("dl", "die").
 *
 * If the source fires first, cancellation loses: the value is returned (a received message must not be dropped)
 * and {@see Cancelled} is thrown at the next suspension point.
 *
 * @api
 * @template T
 * @param callable(Continuation<T>): ((\Closure(): void)|void) $register
 * @return T
 * @throws Cancelled
 */
function suspend(callable $register): mixed
{
    $scope = Scope::current();
    $scope->throwIfCancelled();

    /** @var ContinuationImpl<T> */
    $continuation = new ContinuationImpl();
    $unregister = $register($continuation);
    // After $register: if it has cancelled the scope (say, through a task handle), the wait is interrupted right away.
    $scope->subscribe($continuation);

    // The only check of tracing on the hot path: the traced wait is the rest of this function with events.
    $tracer = Scope::$tracer;

    if ($tracer !== null) {
        return $tracer->park($scope, $continuation, $unregister);
    }

    try {
        return $continuation->park();
    } finally {
        $scope->unsubscribe($continuation);

        // Anything else means there is nothing to undo: $register returned nothing,
        // or it is an arrow function that returned the bool of Continuation::resume() or a Revolt callback id.
        if ($unregister instanceof \Closure) {
            $unregister();
        }
    }
}

/**
 * Cancellation point for CPU-bound code that never waits.
 *
 * Such code starves the event loop: timers, including {@see timeout()} deadlines, cannot fire, and other tasks
 * do not run. So checking a flag is not enough: checkpoint() yields to the event loop for one tick
 * and throws {@see Cancelled} if the scope is cancelled before or during that tick.
 * If the tick resumes it first, the cancellation is thrown at the next suspension point, as with any wait.
 *
 * It costs a full wait, so in a hot loop call it every N iterations, not on every one.
 *
 * @api
 * @throws Cancelled
 */
function checkpoint(): void
{
    suspend(static function (Continuation $continuation) {
        // Not EventLoop::queue(): microtasks run before timers, so a loop of them would starve the deadline.
        $callbackId = EventLoop::defer(static function () use ($continuation) {
            $continuation->resume();
        });

        return static function () use ($callbackId) {
            EventLoop::cancel($callbackId);
        };
    });
}

/**
 * Whether the current scope is cancelled, that is, whether the next wait would throw {@see Cancelled}.
 * Inside {@see shield()} an outer cancellation is not visible.
 *
 * Only reads a flag: unlike {@see checkpoint()}, it does not yield to the event loop and never throws.
 * For cleanup code and for deciding whether a failure is worth logging as an error.
 *
 * @api
 */
function isCancelled(): bool
{
    return Scope::current()->isCancelled();
}

/**
 * Calls $callback once the current scope is cancelled, by its owner or through an ancestor;
 * if it already is, soon. For code that is not cancelled through {@see suspend()}: an `Amp\Cancellation`,
 * a request to abort a query on the server.
 *
 * It only observes the cancellation and cannot cause one. $callback runs from the event loop's microtask queue,
 * never inside the code that cancels, and in the root scope: it must not throw. If the scope is cancelled,
 * it is called exactly once and always before the code of the scope ends: if the queue has not reached it by then,
 * the code leaving the scope calls it itself. It is never called after the returned function is called,
 * even if the cancellation has already happened.
 * Inside {@see shield()} an outer cancellation does not reach it. Once the code of the scope ends without being
 * cancelled, the callback is dropped and never called, so unsubscribing is optional then: a group body that has
 * returned or failed while its tasks run, and a task that has failed, are not cancelled by that failure.
 *
 * @api
 * @param callable(): void $callback
 * @return \Closure(): void unsubscribe, idempotent
 */
function onCancel(callable $callback): \Closure
{
    return Scope::current()->onCancel($callback(...));
}

/**
 * Time left until the deadline of the current scope, null if there is none: the nearest of the enclosing
 * {@see timeout()} calls, tasks included, since a task inherits the deadline under which its group runs.
 * Inside {@see shield()} outer deadlines are not visible, as outer cancellation is not.
 *
 * For passing the deadline on to where a scope cannot reach: a `statement_timeout` of a database, a header of a remote
 * call. Zero once the deadline has passed, even if the scope is not cancelled yet.
 * Only reads a clock: it never waits or throws.
 *
 * @api
 */
function remaining(): ?Duration
{
    $remaining = Scope::current()->remaining();

    return $remaining === null ? null : ns($remaining);
}

/**
 * Cancellable sleep. Zero or less waits as zero, as in Go and Python: until the timers of the next tick of the loop,
 * so other tasks run meanwhile, and it is a cancellation point.
 *
 * @api
 * @throws Cancelled
 */
function delay(Duration $duration): void
{
    // Revolt rejects a negative delay.
    $seconds = max(0, toNs($duration)) / 1e9;

    suspend(static function (Continuation $continuation) use ($seconds) {
        $timer = EventLoop::delay($seconds, static function () use ($continuation) {
            $continuation->resume();
        });

        return static function () use ($timer) {
            EventLoop::cancel($timer);
        };
    });
}

/**
 * Deadline for everything that happens inside $body, including nested groups.
 *
 * An outer cancellation outranks the deadline: if both happened, {@see Cancelled} is thrown, not {@see TimedOut}.
 *
 * A deadline that is zero or negative has already passed: $body still runs, but its first wait throws.
 *
 * @api
 * @template T
 * @param callable(): T $body
 * @return T
 * @throws TimedOut
 */
function timeout(Duration $duration, callable $body): mixed
{
    $result = Expired::run($duration, $body);

    if ($result instanceof Expired) {
        throw new TimedOut($duration, $result->cancelled);
    }

    return $result;
}

/**
 * Like {@see timeout()}, but returns $default instead of throwing
 * {@see TimedOut} once its own deadline cuts $body short.
 *
 * Only this deadline: a {@see TimedOut} of a nested timeout() that $body lets out is not swallowed,
 * and an outer cancellation is rethrown as {@see Cancelled}, as with timeout().
 * Whatever $body has done before the deadline stays done: collect partial results by reference.
 *
 * If $body may return $default itself (null, for example), pass a $default it never returns to tell the expiry apart:
 * `$result = timeoutOr(sec(1), $body, default: $expired = new \stdClass())`, then compare with `=== $expired`.
 *
 * @api
 * @template T
 * @template D
 * @param callable(): T $body
 * @param D $default returned if the deadline cuts $body short
 * @return T|D
 */
function timeoutOr(Duration $duration, callable $body, mixed $default = null): mixed
{
    $result = Expired::run($duration, $body);

    return $result instanceof Expired ? $default : $result;
}

/**
 * Runs $body and does not return until every task started in the group is finished.
 *
 * Tasks are started through the event loop's microtask queue: they begin to run at the body's next wait
 * (or when the body returns), not inside {@see Group::spawn()}.
 *
 * The first failure (of the body or of a task) cancels everything else and is rethrown after all tasks are joined.
 *
 * @api
 * @template T
 * @param callable(Group): T $body
 * @param ?non-empty-string $name what the group is called in a trace (`Trace\GroupCreated::$name`), nowhere else
 * @return T
 */
function group(callable $body, ?string $name = null): mixed
{
    return GroupImpl::run($body, __FUNCTION__, $name);
}

/**
 * Outer cancellation does not get inside. For cleanup: rollback, close frame, returning a connection to a pool.
 * Combine with {@see timeout()} so that the cleanup cannot hang forever.
 *
 * @api
 * @template T
 * @param callable(): T $body
 * @return T
 */
function shield(callable $body): mixed
{
    $parent = Scope::current();
    $scope = new Scope($parent, shield: true);
    Scope::$tracer?->shieldCreated($scope, $parent);

    try {
        $result = $scope->run($body);
        Scope::$tracer?->ended($scope, $result, null);

        return $result;
    } catch (\Throwable $error) {
        Scope::$tracer?->ended($scope, null, $error);

        throw $error;
    } finally {
        $scope->close();
    }
}

/**
 * Failures that happened in the groups $error was rethrown from, but were not rethrown themselves:
 * a group rethrows only its first failure. For example, a sibling task that failed at the same time,
 * or a rollback that failed while a task was being cancelled because of $error.
 *
 * $error itself is left as it was. Each of the returned errors may have suppressed ones of its own,
 * if it came out of a nested group. Nobody sees them unless asked: log them where errors are handled.
 *
 * @api
 * @return list<\Throwable> in the order they happened, empty if there are none
 */
function suppressed(\Throwable $error): array
{
    return Suppressed::of($error);
}

/**
 * All results in the order of $tasks, or the first failure (the rest are cancelled and joined).
 *
 * The result has the keys of $tasks, but one value type for all of them: `['user' => ..., 'orders' => ...]`
 * gives `array<'user'|'orders', User|list<Order>>`. thesis/phpstan-extension gives a type per key:
 * `array{user: User, orders: list<Order>}`. Without it, for results of different types, spawn tasks
 * in a {@see group()} and await their handles, which are typed precisely.
 *
 * @api
 * @template K of array-key
 * @template T
 * @param array<K, callable(): T> $tasks
 * @return array<K, T>
 */
function all(array $tasks): array
{
    return GroupImpl::run(static function (Group $group) use ($tasks) {
        $handles = [];

        foreach ($tasks as $key => $task) {
            $handles[$key] = $group->spawn($task, Internal\taskName($key));
        }

        $results = [];

        foreach ($handles as $key => $handle) {
            $results[$key] = $handle->await();
        }

        return $results;
    }, function: __FUNCTION__);
}

/**
 * Waits for every task, whatever it ends with: a failure neither cancels the rest nor is rethrown.
 * Returns the results and the failures, each with the keys of $tasks and in their order.
 *
 * Settled means finished on its own: {@see Cancelled} is not a result. allSettled() cancels nothing itself,
 * so a task can be cancelled only from outside, and then allSettled() throws {@see Cancelled} as any wait does.
 * For "whatever is ready within 100 ms", put a {@see timeout()} around each task, not around allSettled():
 * its {@see TimedOut} is then a failure of that task.
 *
 * @api
 * @template K of array-key
 * @template T
 * @param array<K, callable(): T> $tasks
 * @return array{array<K, T>, array<K, \Throwable>} the results and the failures
 */
function allSettled(array $tasks): array
{
    return GroupImpl::run(static function (GroupImpl $group) use ($tasks) {
        $handles = [];

        foreach ($tasks as $key => $task) {
            $handles[$key] = $group->spawnWrapped(static function () use ($task) {
                // A result is boxed: it may itself be a \Throwable.
                try {
                    $value = $task();
                } catch (Cancelled $cancelled) {
                    throw $cancelled;
                } catch (\Throwable $error) {
                    Scope::$tracer?->settled(Scope::current(), null, $error);

                    return $error;
                }

                Scope::$tracer?->settled(Scope::current(), $value, null);

                return [$value];
            }, $task, Internal\taskName($key));
        }

        $errors = [];
        $results = [];

        foreach ($handles as $key => $handle) {
            $settled = $handle->await();

            if ($settled instanceof \Throwable) {
                $errors[$key] = $settled;
            } else {
                $results[$key] = $settled[0];
            }
        }

        return [$results, $errors];
    }, function: __FUNCTION__);
}

/**
 * The first $count tasks to succeed win; the rest are cancelled AND joined. For a quorum: two replicas out of three.
 * Returns their results with the keys of $tasks, in the order they succeeded.
 *
 * Failures are ignored while $count successes are still possible. Once they are not, the rest are cancelled
 * and the first failure is rethrown; every other one is among its `suppressed()` ones,
 * including those of the tasks that fail while being cancelled. After $count successes, failures are dropped.
 *
 * @api
 * @template K of array-key
 * @template T
 * @param positive-int $count
 * @param array<K, callable(): T> $tasks
 * @return non-empty-array<K, T>
 * @throws \ValueError if there are fewer than $count tasks: the failure would be known before anything runs
 */
function some(int $count, array $tasks): array
{
    return Internal\some(function: 'some', count: $count, tasks: $tasks);
}

/**
 * The first one to succeed wins; the rest are cancelled AND joined: {@see some()} with a count of one.
 * Failures are ignored while there is a chance to win; if every contender fails, the first failure is rethrown,
 * and the others are among its `suppressed()` ones.
 *
 * @api
 * @template T
 * @param non-empty-array<callable(): T> $tasks
 * @return T
 */
function race(array $tasks): mixed
{
    return array_first(Internal\some(function: 'race', count: 1, tasks: $tasks));
}

/**
 * Bounded concurrency: calls $fn for every item, at most $concurrency at a time. $fn gets the value, not the key.
 *
 * Items are processed by at most $concurrency tasks, not by a task per item: a task takes the next item
 * once it is done with the previous one, so consecutive calls of $fn may share a fiber. A task is started
 * only when an item finds the running ones busy.
 *
 * $items is iterated by these tasks, one at a time, so a generator may suspend (for example, to fetch the next page).
 * The next item is pulled only once a task is free for it, and never after a failure.
 * The first failure cancels the rest and is rethrown.
 *
 * Since a task pulls the items, a generator runs in that task's fiber, not in the caller's: it sees the scoped values
 * of the caller, but not its other fiber-local state. A lock the caller holds through {@see synchronized()}
 * is not held by the task, so a generator that takes the same lock waits
 * for the caller, which waits for each(): a deadlock.
 *
 * @api
 * @template T
 * @param iterable<mixed, T> $items
 * @param positive-int $concurrency
 * @param callable(T): mixed $fn
 */
function each(iterable $items, int $concurrency, callable $fn): void
{
    GroupImpl::run(static function (GroupImpl $group) use ($items, $concurrency, $fn) {
        // Not $fn(...) itself: Workers passes the key too, and an internal function rejects an extra argument.
        Workers::start(
            group: $group,
            items: $items,
            concurrency: $concurrency,
            fn: static fn(mixed $item) => $fn($item),
            traced: $fn,
        );
    }, function: __FUNCTION__);
}

/**
 * {@see each()} that collects the results. Keys are preserved, and so is the order of $items.
 *
 * @api
 * @template K of array-key
 * @template T
 * @template R
 * @param iterable<K, T> $items
 * @param positive-int $concurrency
 * @param callable(T): R $fn
 * @return array<K, R>
 */
function map(iterable $items, int $concurrency, callable $fn): array
{
    $results = [];

    GroupImpl::run(static function (GroupImpl $group) use ($items, $concurrency, $fn, &$results) {
        Workers::start(
            group: $group,
            items: $items,
            concurrency: $concurrency,
            fn: static function (mixed $item, mixed $key) use ($fn, &$results) {
                /** @var T $item */
                /** @var K $key */
                // $fn is called right after its item is taken, in the order of $items:
                // reserving the key first keeps that order.
                $results[$key] = null;
                $results[$key] = $fn($item);
            },
            traced: $fn,
        );
    }, function: __FUNCTION__);

    /** @var array<K, R> Every reserved null has been replaced by a result, or the group has thrown. */
    return $results;
}

/**
 * Runs $fn holding a permit of $semaphore and releases it whatever $fn ends with.
 *
 * Reentrant within a fiber: if the current fiber is already inside synchronized() on the same semaphore,
 * $fn runs right away, without a second permit, so a method under a mutex may call another one under it.
 * A task started inside is another fiber and waits for the permit as anyone else.
 *
 * Use {@see Semaphore::acquire()} directly only when the lock has to outlive one call.
 *
 * @api
 * @template T
 * @param callable(): T $fn
 * @return T
 * @throws Cancelled if the scope is cancelled while waiting for the lock
 */
function synchronized(Semaphore $semaphore, callable $fn): mixed
{
    /** @var \WeakMap<object, \WeakMap<Semaphore, Lock>> $held locks taken by synchronized(), per fiber */
    static $held = new \WeakMap();

    // Only the inner map is kept in a variable, not the fiber's key: see fiberKey().
    // An emptied map stays for a reused Revolt fiber and goes away with the fiber.
    $locks = $held[fiberKey()] ?? null;

    if ($locks === null) {
        /** @var \WeakMap<Semaphore, Lock> */
        $locks = new \WeakMap();
        $held[fiberKey()] = $locks;
    }

    if (isset($locks[$semaphore])) {
        return $fn();
    }

    return Internal\holding(locks: $locks, key: $semaphore, lock: $semaphore->acquire(), fn: $fn);
}

/**
 * {@see synchronized()} for one key of a {@see KeyedSemaphore}: runs $fn holding a permit of $key
 * and releases it whatever $fn ends with.
 *
 * Reentrant within a fiber per key: inside synchronizedKey() on the same semaphore and key, $fn runs right away;
 * another key of the same semaphore is acquired as usual.
 *
 * @api
 * @template T
 * @param callable(): T $fn
 * @return T
 * @throws Cancelled if the scope is cancelled while waiting for the lock
 */
function synchronizedKey(KeyedSemaphore $semaphore, int|string $key, callable $fn): mixed
{
    // Locks taken by synchronizedKey(), per fiber.
    /** @var \WeakMap<object, \WeakMap<KeyedSemaphore, \ArrayObject<array-key, Lock>>> $held */
    static $held = new \WeakMap();

    // As in synchronized(), the fiber's key is not kept in a variable: see fiberKey().
    $semaphores = $held[fiberKey()] ?? null;

    if ($semaphores === null) {
        /** @var \WeakMap<KeyedSemaphore, \ArrayObject<array-key, Lock>> */
        $semaphores = new \WeakMap();
        $held[fiberKey()] = $semaphores;
    }

    // An object, not an array: the locks are added and removed after acquire() may have suspended.
    $locks = $semaphores[$semaphore] ?? null;

    if ($locks === null) {
        /** @var \ArrayObject<array-key, Lock> */
        $locks = new \ArrayObject();
        $semaphores[$semaphore] = $locks;
    }

    if (isset($locks[$key])) {
        return $fn();
    }

    return Internal\holding(locks: $locks, key: $key, lock: $semaphore->acquire($key), fn: $fn);
}

/**
 * Waits for one of the signals and returns the one received: SIGINT or SIGTERM for a graceful shutdown.
 * Cancellable like any other wait. Needs a driver that supports signals (ext-pcntl, ext-ev or ext-uv).
 *
 * The handlers exist only while it waits: a signal that comes before (Revolt installs them at the next tick
 * of the loop) or after gets the default action, so a second Ctrl+C kills the process even if the shutdown hangs.
 * To keep handling a signal, such as SIGHUP to reload, use {@see trapSignals()}.
 *
 * @api
 * @param positive-int $signal
 * @param positive-int ...$signals
 * @throws EventLoop\UnsupportedFeatureException if the driver does not support signals
 */
function awaitSignal(int $signal, int ...$signals): int
{
    return trapSignals(signals: [$signal, ...$signals], fn: static fn(SignalTrap $trap) => $trap->receive());
}

/**
 * Catches the signals while $fn runs, instead of their default action, and $fn receives them from the trap:
 * SIGHUP to reload, SIGUSR1 to reopen logs. A signal that comes during a reload waits in the trap.
 * Needs a driver that supports signals (ext-pcntl, ext-ev or ext-uv).
 *
 * Outside $fn a signal gets the default action. Revolt installs the handlers at the next tick of the loop,
 * so trapSignals() waits for it before calling $fn: it is a cancellation point, and $fn does not run
 * in a cancelled scope.
 *
 * @api
 * @template T
 * @param non-empty-array<positive-int> $signals
 * @param callable(SignalTrap): T $fn
 * @return T
 * @throws Cancelled if the scope is cancelled before $fn runs
 * @throws EventLoop\UnsupportedFeatureException if the driver does not support signals
 */
function trapSignals(array $signals, callable $fn): mixed
{
    return SignalTrapImpl::trap($signals, $fn);
}

/**
 * Runs an amphp operation so that it is cancelled together with the current scope.
 *
 * $fn receives a {@see Cancellation} requested while the current scope is cancelled
 * (inside {@see shield()} an outer cancellation does not request it). It is bound to the current scope: do not keep it
 * beyond $fn. If $fn throws {@see CancelledException} while the scope is cancelled, {@see Cancelled} is thrown instead,
 * as from any other wait. Otherwise the exception is the operation's own (say, a `TimeoutCancellation` passed
 * alongside), and it is rethrown as is.
 *
 * Like {@see suspend()}, it throws {@see Cancelled} right away if the scope is already cancelled.
 *
 * @api
 * @template T
 * @param callable(Cancellation): T $fn
 * @return T
 * @throws Cancelled
 */
function ampCancellable(callable $fn): mixed
{
    $scope = Scope::current();
    $scope->throwIfCancelled();

    try {
        return $fn(new ScopeCancellation($scope));
    } catch (CancelledException $exception) {
        if ($scope->isCancelled()) {
            throw Cancelled::create();
        }

        throw $exception;
    }
}

/**
 * Runs $fn on a virtual clock and on a fresh Revolt driver whose timers follow it. The clock starts at the current
 * {@see Instant::now()} rounded up to a whole second, so a time captured before the call stays valid in it.
 * The clock jumps to the next timer whenever nothing can run: `delay(hours(1))` is over at once, timers fire exactly
 * in the order of their deadlines, and a deadlock fails at once instead of hanging. CPU work takes no time:
 * see {@see burnCpu()}. {@see Instant::now()} and deadlines follow the clock, whatever the driver
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
function virtualTime(callable $fn, ?EventLoop\Driver $driver = null): mixed
{
    // In a fiber, the driver it runs on cannot be stopped from inside: virtualTime() would spin forever.
    if (\Fiber::getCurrent() !== null) {
        throw new \LogicException('virtualTime() must be called in {main}, not in a fiber.');
    }

    if ($driver === null) {
        $driver = new EventLoop\DriverFactory()->create();
    } elseif ($driver === EventLoop::getDriver() || $driver->isRunning() || $driver->getIdentifiers() !== []) {
        throw new \LogicException(
            message: 'virtualTime() needs a fresh driver: not the current one, not running and with no callbacks.',
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
    // at the end of time (an outer burnCpu() of centuries): the rounding would overflow.
    $now = Scope::now();
    $second = toNs(sec(1));
    $start = $now <= PHP_INT_MAX - ($second - 1) ? intdiv($now + $second - 1, $second) * $second : $now;
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
 * Takes $duration without yielding to the event loop, as CPU work would: a deadline passes,
 * but its timer fires only at the next suspension. Within {@see virtualTime()}, it moves the virtual clock
 * at once; outside, it spins on the real clock.
 *
 * @api
 * @throws \ValueError if $duration is negative: the clock is monotonic
 */
function burnCpu(Duration $duration): void
{
    $nanoseconds = toNs($duration);

    if ($nanoseconds < 0) {
        throw new \ValueError('The clock is monotonic: burnCpu() cannot take a negative duration.');
    }

    if (Scope::$virtualClock !== null) {
        Scope::$virtualClock->advance($nanoseconds);

        return;
    }

    // Spins rather than sleeps: the CPU is busy, as in the code it stands for, and a signal does not cut it short.
    $end = Scope::after($nanoseconds);

    while (Scope::now() < $end) {
        // burns
    }
}
