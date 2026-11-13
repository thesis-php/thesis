<?php

declare(strict_types=1);

namespace Thesis\Async;

use Revolt\EventLoop;
use Thesis\Async\Internal\Context;

/**
 * THE suspension point: every wait in the library goes through it, so cancellation is implemented once.
 *
 * $arm receives a {@see Waker}, registers it wherever the event comes from (timer, stream watcher, wait queue)
 * and may return $disarm that undoes the registration. $disarm is called only if the wait is interrupted
 * by cancellation.
 *
 * If the source fires first, cancellation loses: the value is returned (a received message must not be dropped)
 * and {@see Cancelled} is thrown at the next suspension point.
 *
 * @api
 * @template T
 * @param callable(Waker<T>): (null|callable(): void) $arm
 * @return T
 * @throws Cancelled
 */
function suspend(callable $arm): mixed
{
    $scope = Scope::current();
    $scope->checkpoint();

    /** @var Waker<T> */
    $waker = new Waker();
    $disarm = $arm($waker);
    $interrupted = false;

    $unsubscribe = $scope->onCancel(static function (Scope $origin) use ($waker, &$interrupted) {
        $interrupted = $waker->throw(new Cancelled($origin));
    });

    try {
        return $waker->park();
    } finally {
        $unsubscribe();

        if ($interrupted && $disarm !== null) {
            $disarm();
        }
    }
}

/**
 * @api
 * @throws Cancelled
 */
function delay(float $seconds): void
{
    suspend(static function (Waker $waker) use ($seconds) {
        $timer = EventLoop::delay($seconds, static function () use ($waker) {
            $waker->resume();
        });

        return static function () use ($timer) {
            EventLoop::cancel($timer);
        };
    });
}

/**
 * Runs $body and does not return until every task started in the group is finished.
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
    return Group::run($body);
}

/**
 * Deadline for everything that happens inside $body, including nested groups.
 *
 * @api
 * @template T
 * @param callable(): T $body
 * @return T
 * @throws TimedOut
 */
function timeout(float $seconds, callable $body): mixed
{
    $scope = new Scope(Scope::current());
    $timer = EventLoop::delay($seconds, static function () use ($scope) {
        $scope->cancel();
    });

    try {
        return Context::enter($scope, $body);
    } catch (Cancelled $cancelled) {
        throw $cancelled->origin === $scope ? new TimedOut($seconds) : $cancelled;
    } finally {
        EventLoop::cancel($timer);
        $scope->close();
    }
}

/**
 * Outer cancellation does not get inside. For cleanup: rollback, close frame, returning a connection to a pool.
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
        return Context::enter($scope, $body);
    } finally {
        $scope->close();
    }
}

/**
 * All results, or the first failure (the rest are cancelled).
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
            $tasks[$key] = $group->go($fn);
        }

        $results = [];

        foreach ($tasks as $key => $task) {
            $results[$key] = $task->await();
        }

        return $results;
    });
}

/**
 * The first one to finish wins; the losers are cancelled AND joined.
 *
 * @api
 * @template T
 * @param callable(): T $fn
 * @param callable(): T ...$fns
 * @return T
 */
function race(callable $fn, callable ...$fns): mixed
{
    /** @var list<T> $winner */
    $winner = [];

    group(static function (Group $group) use ($fn, $fns, &$winner) {
        foreach ([$fn, ...$fns] as $contender) {
            $group->go(static function () use ($contender, $group, &$winner) {
                $value = $contender();

                if ($winner === []) {
                    $winner = [$value];
                    $group->cancel();
                }
            });
        }
    });

    /** @var array{T} $winner */
    return $winner[0];
}

/**
 * Bounded concurrency: $concurrency workers pull from a shared iterator.
 *
 * The iterable itself must not suspend while producing an item.
 *
 * @api
 * @template T
 * @param iterable<T> $items
 * @param positive-int $concurrency
 * @param callable(T): mixed $fn
 */
function each(iterable $items, int $concurrency, callable $fn): void
{
    $iterator = (static fn() => yield from $items)();

    group(static function (Group $group) use ($iterator, $concurrency, $fn) {
        for ($worker = 0; $worker < $concurrency; ++$worker) {
            $group->go(static function () use ($iterator, $fn) {
                while ($iterator->valid()) {
                    $item = $iterator->current();
                    $iterator->next();
                    $fn($item);
                }
            });
        }
    });
}
