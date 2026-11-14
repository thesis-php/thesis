<?php

declare(strict_types=1);

namespace Thesis\Async;

use Amp\Cancellation;
use Amp\CancelledException;
use Revolt\EventLoop;
use Thesis\Async\Internal\ContinuationImpl;
use Thesis\Async\Internal\GroupImpl;
use Thesis\Async\Internal\Scope;
use Thesis\Async\Internal\ScopeCancellation;
use Thesis\Async\Internal\Suppressed;
use Thesis\Async\Internal\Workers;
use function Thesis\Async\Internal\fiberKey;

/**
 * THE suspension point: every wait in the library goes through it, so cancellation is implemented once.
 *
 * $register receives the {@see Continuation} of this wait and registers it wherever the event comes from
 * (timer, stream watcher, wait queue). It may return $unregister that undoes the registration.
 * $unregister is always called once the wait is over, whatever ended it,
 * so it must be harmless for a source that has already fired.
 *
 * If the source fires first, cancellation loses: the value is returned (a received message must not be dropped)
 * and {@see Cancelled} is thrown at the next suspension point.
 *
 * @api
 * @template T
 * @param callable(Continuation<T>): ((callable(): void)|void) $register
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
        // or it is an arrow function that returned the bool of Continuation::resume().
        if (\is_callable($unregister)) {
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
 * never inside the code that cancels, and in the root scope: it must not throw.
 * It is called at most once and never after the returned function is called, even if the cancellation has already happened.
 * Inside {@see shield()} an outer cancellation does not reach it. Once the scope ends without being cancelled,
 * the callback is dropped and never called, so unsubscribing is optional then.
 *
 * @api
 * @param \Closure(): void $callback
 * @return \Closure(): void unsubscribe, idempotent
 */
function onCancel(\Closure $callback): \Closure
{
    return Scope::current()->onCancel($callback);
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
 * @api
 * @throws Cancelled
 */
function delay(float $seconds): void
{
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
    return SignalTrap::trap($signals, $fn);
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
 * Deadline for everything that happens inside $body, including nested groups.
 *
 * An outer cancellation outranks the deadline: if both happened, {@see Cancelled} is thrown, not {@see TimedOut}.
 *
 * @api
 * @template T
 * @param callable(): T $body
 * @return T
 * @throws TimedOut
 */
function timeout(float $seconds, callable $body): mixed
{
    $parent = Scope::current();
    $scope = new Scope($parent, timeout: $seconds);
    $timer = EventLoop::delay($seconds, static function () use ($scope) {
        $scope->cancel();
    });

    try {
        return $scope->run($body);
    } catch (Cancelled $cancelled) {
        // Only the timer cancels this scope, so if the parent is not cancelled, the deadline is the reason.
        if ($scope->isCancelled() && !$parent->isCancelled()) {
            throw new TimedOut($seconds, $cancelled);
        }

        throw $cancelled;
    } finally {
        EventLoop::cancel($timer);
        $scope->close();
    }
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
    if ($count > \count($tasks)) {
        throw new \ValueError(\sprintf('some() needs at least %d tasks, got %d.', $count, \count($tasks)));
    }

    // Once more than this many have failed, $count successes are no longer possible.
    $tolerated = \count($tasks) - $count;
    $results = [];
    $failure = null;
    $failures = 0;

    group(static function (Group $group) use ($count, $tasks, $tolerated, &$results, &$failure, &$failures) {
        foreach ($tasks as $key => $task) {
            $group->spawn(static function () use ($key, $task, $group, $count, $tolerated, &$results, &$failure, &$failures) {
                try {
                    $value = $task();
                } catch (Cancelled $cancelled) {
                    throw $cancelled;
                } catch (\Throwable $error) {
                    if (\count($results) === $count) {
                        return;
                    }

                    if ($failure === null) {
                        $failure = $error;
                    } else {
                        Suppressed::add($failure, $error);
                    }

                    if (++$failures === $tolerated + 1) {
                        $group->cancelTasks();
                    }

                    return;
                }

                // Successes and failures cannot both be decided: that would take more tasks than there are.
                if ($failures > $tolerated || \count($results) === $count) {
                    return;
                }

                $results[$key] = $value;

                if (\count($results) === $count) {
                    $group->cancelTasks();
                }
            });
        }
    });

    if (\count($results) < $count) {
        /** @var \Throwable $failure */
        throw $failure;
    }

    return $results;
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
    return array_first(some(1, $tasks));
}

/**
 * Bounded concurrency: calls $fn for every item, at most $concurrency at a time.
 *
 * Items are processed by at most $concurrency tasks, not by a task per item: a task takes the next item
 * once it is done with the previous one, so consecutive calls of $fn may share a fiber. A task is started
 * only when an item finds the running ones busy.
 *
 * $items is iterated by these tasks, one at a time, so a generator may suspend (for example, to fetch the next page).
 * The next item is pulled only once a task is free for it, and never after a failure.
 * The first failure cancels the rest and is rethrown.
 *
 * @api
 * @template K
 * @template T
 * @param iterable<K, T> $items
 * @param callable(T, K): mixed $fn
 * @param positive-int $concurrency
 */
function each(iterable $items, callable $fn, int $concurrency): void
{
    group(static function (Group $group) use ($items, $fn, $concurrency) {
        new Workers($group, $items, $fn, $concurrency)->start();
    });
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

    $locks[$semaphore] = $semaphore->acquire();

    try {
        return $fn();
    } finally {
        $locks[$semaphore]->release();
        unset($locks[$semaphore]);
    }
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
function synchronizedKey(KeyedSemaphore $semaphore, string $key, callable $fn): mixed
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

    $locks[$key] = $semaphore->acquire($key);

    try {
        return $fn();
    } finally {
        $locks[$key]->release();
        unset($locks[$key]);
    }
}

/**
 * {@see each()} that collects the results. Keys are preserved, and so is the order of $items.
 *
 * @api
 * @template K of array-key
 * @template T
 * @template R
 * @param iterable<K, T> $items
 * @param callable(T, K): R $fn
 * @param positive-int $concurrency
 * @return array<K, R>
 */
function map(iterable $items, callable $fn, int $concurrency): array
{
    $results = [];

    each(
        $items,
        static function (mixed $item, mixed $key) use ($fn, &$results) {
            /** @var T $item */
            /** @var K $key */
            // $fn is called right after its item is taken, in the order of $items: reserving the key first keeps that order.
            $results[$key] = null;
            $results[$key] = $fn($item, $key);
        },
        $concurrency,
    );

    /** @var array<K, R> Every reserved null has been replaced by a result, or each() has thrown. */
    return $results;
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
