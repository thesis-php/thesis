<?php

declare(strict_types=1);

namespace Thesis\Async;

use Revolt\EventLoop;
use Thesis\Async\Internal\ContinuationImpl;
use Thesis\Async\Internal\GroupImpl;
use Thesis\Async\Internal\Scope;

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
        $callback = EventLoop::defer(static function () use ($continuation) {
            $continuation->resume();
        });

        return static function () use ($callback) {
            EventLoop::cancel($callback);
        };
    });
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
    $scope = new Scope($parent);
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
 * All results in the order of $fns, or the first failure (the rest are cancelled and joined).
 *
 * The result has the keys of $fns, but one value type for all of them: `['user' => ..., 'orders' => ...]`
 * gives `array<'user'|'orders', User|list<Order>>`. For results of different types, spawn tasks in a {@see group()}
 * and await their handles, which are typed precisely.
 *
 * @api
 * @template K of array-key
 * @template T
 * @param array<K, callable(): T> $fns
 * @return array<K, T>
 */
function all(array $fns): array
{
    return group(static function (Group $group) use ($fns) {
        $tasks = [];

        foreach ($fns as $key => $fn) {
            $tasks[$key] = $group->spawn($fn);
        }

        $results = [];

        foreach ($tasks as $key => $task) {
            $results[$key] = $task->await();
        }

        return $results;
    });
}

/**
 * The first one to succeed wins; the rest are cancelled AND joined.
 * Failures are ignored while there is a chance to win; if every contender fails, the first failure is rethrown.
 *
 * @api
 * @template T
 * @param non-empty-array<callable(): T> $fns
 * @return T
 * @throws \ValueError if $fns is empty: there would be no winner and no failure to report
 */
function race(array $fns): mixed
{
    /** @phpstan-ignore identical.alwaysFalse */
    if ($fns === []) {
        throw new \ValueError('A race needs at least one contender.');
    }

    /** @var list<T> $winner */
    $winner = [];
    $failure = null;

    group(static function (Group $group) use ($fns, &$winner, &$failure) {
        foreach ($fns as $contender) {
            $group->spawn(static function () use ($contender, $group, &$winner, &$failure) {
                try {
                    $value = $contender();
                } catch (Cancelled $cancelled) {
                    throw $cancelled;
                } catch (\Throwable $error) {
                    $failure ??= $error;

                    return;
                }

                if ($winner === []) {
                    $winner = [$value];
                    $group->cancelTasks();
                }
            });
        }
    });

    if ($winner === []) {
        /** @var \Throwable $failure */
        throw $failure;
    }

    return $winner[0];
}

/**
 * Bounded concurrency: calls $fn for every item, at most $concurrency at a time.
 *
 * $items is iterated in the calling fiber, so a generator may suspend (for example, to fetch the next page).
 * The next item is pulled only once a slot is free for it, and never after a failure.
 * The first failure cancels the rest and is rethrown.
 *
 * @api
 * @template K
 * @template T
 * @param iterable<K, T> $items
 * @param callable(T, K): mixed $fn
 * @param positive-int $concurrency
 * @throws \ValueError if $concurrency is not positive
 */
function each(iterable $items, callable $fn, int $concurrency): void
{
    /** @phpstan-ignore smaller.alwaysFalse */
    if ($concurrency < 1) {
        throw new \ValueError(\sprintf('Concurrency must be positive, got %d.', $concurrency));
    }

    group(static function (Group $group) use ($items, $fn, $concurrency) {
        $body = Scope::current();
        $running = 0;
        /** @var ?Continuation<null> */
        $slotWaiter = null;

        foreach ($items as $key => $item) {
            ++$running;
            $group->spawn(static function () use ($fn, $item, $key, &$running, &$slotWaiter) {
                try {
                    $fn($item, $key);
                } finally {
                    --$running;
                    $slotWaiter?->resume();
                }
            });

            // The slot is waited for here, before foreach advances $items: an item is not pulled
            // until it can start (the iterable may be a queue, a cursor or a paginated API).
            while ($running >= $concurrency) {
                suspend(static function (Continuation $continuation) use (&$slotWaiter) {
                    /** @var Continuation<null> $continuation */
                    $slotWaiter = $continuation;

                    return static function () use (&$slotWaiter) {
                        $slotWaiter = null;
                    };
                });
            }

            // A failing task frees its slot before it fails the group, and an event wins over cancellation,
            // so the wait above returns normally even though the body is cancelled by now. Nothing more is pulled then.
            $body->throwIfCancelled();
        }
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
 * @param callable(T, K): R $fn
 * @param positive-int $concurrency
 * @return array<K, R>
 * @throws \ValueError if $concurrency is not positive
 */
function map(iterable $items, callable $fn, int $concurrency): array
{
    $results = [];

    each(
        $items,
        static function (mixed $item, mixed $key) use ($fn, &$results) {
            /** @var T $item */
            /** @var K $key */
            // Tasks start in the order of $items: reserving the key first keeps that order in the result.
            $results[$key] = null;
            $results[$key] = $fn($item, $key);
        },
        $concurrency,
    );

    /** @var array<K, R> Every reserved null has been replaced by a result, or each() has thrown. */
    return $results;
}
