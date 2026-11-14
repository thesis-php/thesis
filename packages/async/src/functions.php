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
use Thesis\Async\Internal\Workers;
use function Thesis\Async\Internal\fiberKey;

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
 * it is called exactly once and always before the scope ends: if the queue has not reached it by then,
 * the code leaving the scope calls it itself. It is never called after the returned function is called,
 * even if the cancellation has already happened.
 * Inside {@see shield()} an outer cancellation does not reach it. Once the scope ends without being cancelled,
 * the callback is dropped and never called, so unsubscribing is optional then.
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
 * Monotonic time in seconds, on the clock of deadlines and Revolt's timers: for measuring and for deadlines
 * computed ahead, `timeout($at - now(), ...)`. Not wall-clock time: only differences make sense.
 *
 * @api
 */
function now(): float
{
    return Scope::now();
}

/**
 * Seconds left until the deadline of the current scope, null if there is none: the nearest of the enclosing
 * {@see timeout()} calls, tasks included, since a task inherits the deadline under which its group runs.
 * Inside {@see shield()} outer deadlines are not visible, as outer cancellation is not.
 *
 * For passing the deadline on to where a scope cannot reach: a `statement_timeout` of a database, a header of a remote call.
 * Zero once the deadline has passed, even if the scope is not cancelled yet. Only reads a clock: it never waits or throws.
 *
 * @api
 */
function remaining(): ?float
{
    return Scope::current()->remaining();
}

/**
 * Cancellable sleep. Zero or less waits as zero, as in Go and Python: until the timers of the next tick of the loop,
 * so other tasks run meanwhile, and it is a cancellation point.
 *
 * @api
 * @throws Cancelled
 * @throws \ValueError if $seconds is NAN
 */
function delay(float $seconds): void
{
    if (is_nan($seconds)) {
        throw new \ValueError('delay() needs a number of seconds, got NAN.');
    }

    // Revolt rejects a negative delay.
    $seconds = max(0.0, $seconds);

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
 * @throws \ValueError if $seconds is NAN, before $body runs
 */
function timeout(float $seconds, callable $body): mixed
{
    $result = Expired::run($seconds, $body);

    if ($result instanceof Expired) {
        throw new TimedOut($seconds, $result->cancelled);
    }

    return $result;
}

/**
 * Like {@see timeout()}, but returns $default instead of throwing {@see TimedOut} once its own deadline cuts $body short.
 *
 * Only this deadline: a {@see TimedOut} of a nested timeout() that $body lets out is not swallowed,
 * and an outer cancellation is rethrown as {@see Cancelled}, as with timeout().
 * Whatever $body has done before the deadline stays done: collect partial results by reference.
 *
 * If $body may return $default itself (null, for example), pass a $default it never returns to tell the expiry apart:
 * `$result = timeoutOr(1, $expired = new \stdClass(), $body)`, then compare with `=== $expired`.
 *
 * @api
 * @template T
 * @template D
 * @param D $default returned if the deadline cuts $body short
 * @param callable(): T $body
 * @return T|D
 * @throws \ValueError if $seconds is NAN, before $body runs
 */
function timeoutOr(float $seconds, mixed $default, callable $body): mixed
{
    $result = Expired::run($seconds, $body);

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
 * @return T
 */
function group(callable $body): mixed
{
    return GroupImpl::run($body);
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
    $scope = new Scope(Scope::current(), shield: true);

    try {
        return $scope->run($body);
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
 * gives `array<'user'|'orders', User|list<Order>>`. For results of different types, spawn tasks in a {@see group()}
 * and await their handles, which are typed precisely.
 *
 * @api
 * @template K of array-key
 * @template T
 * @param array<K, callable(): T> $tasks
 * @return array<K, T>
 */
function all(array $tasks): array
{
    return group(static function (Group $group) use ($tasks) {
        $handles = [];

        foreach ($tasks as $key => $task) {
            $handles[$key] = $group->spawn($task);
        }

        $results = [];

        foreach ($handles as $key => $handle) {
            $results[$key] = $handle->await();
        }

        return $results;
    });
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
    return group(static function (Group $group) use ($tasks) {
        $handles = [];

        foreach ($tasks as $key => $task) {
            $handles[$key] = $group->spawn(static function () use ($task) {
                // A result is boxed: it may itself be a \Throwable.
                try {
                    return [$task()];
                } catch (Cancelled $cancelled) {
                    throw $cancelled;
                } catch (\Throwable $error) {
                    return $error;
                }
            });
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
    });
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
    return Internal\some('some', $count, $tasks);
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
    return array_first(Internal\some('race', 1, $tasks));
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
 * is not held by the task, so a generator that takes the same lock waits for the caller, which waits for each(): a deadlock.
 *
 * @api
 * @template T
 * @param iterable<mixed, T> $items
 * @param positive-int $concurrency
 * @param callable(T): mixed $fn
 */
function each(iterable $items, int $concurrency, callable $fn): void
{
    group(static function (Group $group) use ($items, $concurrency, $fn) {
        // Not $fn(...) itself: Workers passes the key too, and an internal function rejects an extra argument.
        Workers::start($group, $items, $concurrency, static fn(mixed $item) => $fn($item));
    });
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

    group(static function (Group $group) use ($items, $concurrency, $fn, &$results) {
        Workers::start(
            $group,
            $items,
            $concurrency,
            static function (mixed $item, mixed $key) use ($fn, &$results) {
                /** @var T $item */
                /** @var K $key */
                // $fn is called right after its item is taken, in the order of $items: reserving the key first keeps that order.
                $results[$key] = null;
                $results[$key] = $fn($item);
            },
        );
    });

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

    return Internal\holding($locks, $semaphore, $semaphore->acquire(), $fn);
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
    /** @var \WeakMap<object, \WeakMap<KeyedSemaphore, \ArrayObject<array-key, Lock>>> $held locks taken by synchronizedKey(), per fiber */
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

    return Internal\holding($locks, $key, $semaphore->acquire($key), $fn);
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
    return trapSignals(
        signals: [$signal, ...$signals],
        fn: static fn(SignalTrap $trap) => $trap->receive(),
    );
}

/**
 * Catches the signals while $fn runs, instead of their default action, and $fn receives them from the trap:
 * SIGHUP to reload, SIGUSR1 to reopen logs. A signal that comes during a reload waits in the trap.
 * Needs a driver that supports signals (ext-pcntl, ext-ev or ext-uv).
 *
 * Outside $fn, and before the next tick of the loop, when Revolt installs the handlers, a signal gets the default action.
 *
 * @api
 * @template T
 * @param non-empty-array<positive-int> $signals
 * @param callable(SignalTrap): T $fn
 * @return T
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
 * as from any other wait. Otherwise the exception is the operation's own (say, a `TimeoutCancellation` passed alongside),
 * and it is rethrown as is.
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
            throw new Cancelled();
        }

        throw $exception;
    }
}
