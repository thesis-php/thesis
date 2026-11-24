<?php

declare(strict_types=1);

namespace Thesis\Async;

use Thesis\Async\Internal\ContinuationImpl;
use Thesis\Async\Internal\Expired;
use Thesis\Async\Internal\GroupImpl;
use Thesis\Async\Internal\Loop;
use Thesis\Async\Internal\Scope;
use Thesis\Async\Internal\SignalTrapImpl;
use Thesis\Async\Internal\Suppressed;
use Thesis\Async\Internal\VirtualClock;
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
 * so it must be harmless for a source that has already fired. Anything but a closure is ignored,
 * a callable string too: an arrow function that resumes at once returns what it has called.
 *
 * If the scope is already cancelled, the wait throws before calling $register.
 * If the source fires first, cancellation loses: the value is returned (a received message must not be dropped)
 * and {@see Cancelled} is thrown at the next suspension point.
 *
 * @api
 * @template T
 * @param callable(Continuation<T>): ((\Closure(): void)|void) $register
 * @return T
 * @throws Cancelled if the scope is cancelled before registration or cancellation interrupts the wait
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

    // Observers may attach or detach while the continuation is parked.
    Scope::$tracer?->suspended($scope);
    $error = null;

    try {
        return $continuation->park();
    } catch (\Throwable $error) {
        throw $error;
    } finally {
        $scope->unsubscribe($continuation);

        // Anything else means there is nothing to undo: $register returned nothing,
        // or it is an arrow function that returned what it has called, such as the bool of Continuation::resume().
        if ($unregister instanceof \Closure) {
            $unregister();
        }
        Scope::$tracer?->resumed($scope, $error);
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
 */
function checkpoint(): void
{
    // The steps of suspend() without its closures, about a fifth of such a wait: the check of cancellation,
    // the registration, the subscription, the park, and the unregistration whatever ends it.
    $scope = Scope::current();
    $scope->throwIfCancelled();

    /** @var ContinuationImpl<bool> */
    $continuation = new ContinuationImpl();
    $loop = Loop::get();
    // To the next tick, not to the microtasks: they run before the timers, a loop of them would starve a deadline.
    $loop->defer($continuation);
    $scope->subscribe($continuation);
    Scope::$tracer?->suspended($scope);
    $error = null;

    try {
        $continuation->park();
    } catch (\Throwable $error) {
        throw $error;
    } finally {
        $scope->unsubscribe($continuation);
        $loop->undefer($continuation);
        Scope::$tracer?->resumed($scope, $error);
    }
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
    return Scope::current()->isCancelled;
}

/**
 * Calls $callback once the current scope is cancelled, by its owner or through an ancestor;
 * if it already is, soon. For code that is not cancelled through {@see suspend()}: a cancellation token of another library,
 * a request to abort a query on the server.
 *
 * It only observes the cancellation and cannot cause one. $callback runs from the event loop's microtask queue,
 * never inside the code that cancels, in the root scope and outside a fiber: it cannot wait and must not throw.
 * A throw goes to the event loop, which throws it into the wait of the group that runs it in {main}: a failure. If the scope is cancelled,
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
    return Scope::current()->onCancel($callback);
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
 */
function delay(Duration $duration): void
{
    // The steps of suspend() without its closures, as in checkpoint().
    $scope = Scope::current();
    $scope->throwIfCancelled();

    /** @var ContinuationImpl<bool> */
    $continuation = new ContinuationImpl();
    $loop = Loop::get();
    $timer = $loop->delay(toNs($duration), $continuation);
    $scope->subscribe($continuation);
    Scope::$tracer?->suspended($scope);
    $error = null;

    try {
        $continuation->park();
    } catch (\Throwable $error) {
        throw $error;
    } finally {
        $scope->unsubscribe($continuation);
        $loop->cancel($timer);
        Scope::$tracer?->resumed($scope, $error);
    }
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
 * Returns the first $count successful results in completion order, with their original keys.
 * Cancels and joins the remaining tasks. For example, wait for two replicas out of three.
 *
 * If $count successes become impossible, cancels and joins the remaining tasks and rethrows
 * the first failure. Pass it to `suppressed()` for other failures, including failures during cancellation.
 * On success, failures are discarded.
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
 * Returns the first successful result and cancels and joins the remaining tasks. See {@see some()} with $count = 1.
 * If all tasks fail, it rethrows the first failure. Use `suppressed()` to get the rest.
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
                // A generator can repeat a key. Each occurrence writes to its own cell even if it finishes last.
                $results[$key] = &$result;
                $result = $fn($item);
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
 */
function synchronized(Semaphore $semaphore, callable $fn): mixed
{
    /** @var \WeakMap<object, \WeakMap<Semaphore, Lock>> $held */
    $held = Internal\HELD_LOCKS;

    // Only the inner map is kept in a variable, not the fiber's key: see fiberKey().
    // An emptied map stays for the next call in the fiber and goes away with it.
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
 */
function synchronizedKey(KeyedSemaphore $semaphore, int|string $key, callable $fn): mixed
{
    /** @var \WeakMap<object, \WeakMap<KeyedSemaphore, \ArrayObject<array-key, Lock>>> $held */
    $held = Internal\HELD_KEYED_LOCKS;

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
 * Registers a callback in the current loop. It runs between fibers and must not suspend.
 * Multiple subscriptions to the same closure and signal call it once per delivery.
 * Call the returned closure in the subscribing loop to remove this subscription. Repeated calls are harmless.
 *
 * @api
 * @param positive-int $signal
 * @param \Closure(int): void $handler
 * @return \Closure(): void
 * @throws \LogicException without ext-pcntl
 */
function onSignal(int $signal, \Closure $handler): \Closure
{
    if (!\function_exists('pcntl_signal')) {
        throw new \LogicException('Signals need ext-pcntl.');
    }

    $loop = Loop::get();
    $loop->onSignal($signal, $handler);

    return static function () use (&$loop, $signal, $handler) {
        if ($loop === null) {
            return;
        }
        if ($loop !== Loop::get()) {
            throw new \LogicException('Unsubscribe from the signal in the loop that subscribed to it.');
        }

        $loop->offSignal($signal, $handler);
        $loop = null;
    };
}

/**
 * Returns the first requested signal it receives. Use SIGINT or SIGTERM for graceful shutdown.
 * The wait is cancellable and requires ext-pcntl.
 *
 * The handlers exist only while it waits: a signal that comes before or after gets the default action,
 * so a second Ctrl+C kills the process even if the shutdown hangs.
 * To keep handling a signal, such as SIGHUP to reload, use {@see trapSignals()}.
 *
 * @api
 * @no-named-arguments
 * @param positive-int $signal
 * @param positive-int ...$signals
 * @throws \LogicException without ext-pcntl
 */
function awaitSignal(int $signal, int ...$signals): int
{
    return trapSignals(signals: [$signal, ...$signals], fn: static fn(SignalTrap $trap) => $trap->receive());
}

/**
 * Catches the signals while $fn runs, instead of their default action, and $fn receives them from the trap:
 * SIGHUP to reload, SIGUSR1 to reopen logs. A signal that comes during a reload waits in the trap.
 * Needs ext-pcntl.
 *
 * Outside $fn a signal gets the default action. Like a wait, it throws {@see Cancelled} without calling $fn
 * if the scope is cancelled.
 *
 * @api
 * @template T
 * @param non-empty-array<positive-int> $signals
 * @param callable(SignalTrap): T $fn
 * @return T
 * @throws Cancelled if the scope is cancelled before $fn runs
 * @throws \LogicException without ext-pcntl
 */
function trapSignals(array $signals, callable $fn): mixed
{
    return SignalTrapImpl::trap($signals, $fn);
}

/**
 * Runs $fn on a virtual clock, in a fiber of an event loop of its own whose timers follow the clock. The clock starts
 * at the current {@see Instant::now()} rounded up to a whole second, so a time captured before the call stays valid
 * in it. The clock jumps to the next timer whenever nothing can run: `delay(hours(1))` is over at once, timers
 * fire exactly in the order of their deadlines, and a deadlock fails at once instead of hanging. CPU work takes
 * no time: see {@see burnCpu()}. {@see Instant::now()} and deadlines follow the clock.
 *
 * Streams and signals stay real. $fn runs in the root scope, as in {main}: nothing outside cancels it.
 * Whatever $fn leaves in the loop is dropped with it, and the previous clock is restored afterwards.
 * Calls nest: an inner one has a clock and a loop of its own, and blocks the outer loop until it returns.
 * An object that keeps times, such as a rate limiter, must not be shared between two calls that run one after
 * another, or used after the call in the outer clock: each clock goes its own way,
 * and such a time may be in the future of the other.
 *
 * With a $seed, the loop shuffles by it the fibers that are ready in one tick, woken by timers, streams or other
 * fibers: a test meets the orders that the kernel and the timing of a real run may give, and the seed replays one
 * of them. Without one, they run in the order they became ready.
 *
 * @api
 * @template T
 * @param callable(): T $fn
 * @return T
 */
function virtualTime(callable $fn, ?int $seed = null): mixed
{
    $previousClock = Scope::$virtualClock;
    // Not zero: a time captured outside, a deadline or a rate limiter, stays in the past. Rounded up, so that
    // the clock does not go back, to a whole second, so that the times of a dump read well. Not rounded
    // at the end of time (an outer burnCpu() of centuries): the rounding would overflow.
    $now = Scope::now();
    $second = toNs(sec(1));
    $start = $now <= PHP_INT_MAX - ($second - 1) ? intdiv($now + $second - 1, $second) * $second : $now;
    $clock = new VirtualClock($start);
    $loop = new Loop($clock, $seed);
    Scope::$virtualClock = $clock;

    try {
        return $loop->run($fn);
    } finally {
        // What is left in the loop is dropped with it.
        $loop->release();
        Scope::$virtualClock = $previousClock;
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
