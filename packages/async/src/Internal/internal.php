<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Thesis\Async\Cancelled;
use Thesis\Async\Group;
use Thesis\Async\Lock;
use function Thesis\Async\group;

/**
 * The key of the current fiber in the per-fiber maps: {@see CURRENT_SCOPE}, {@see CONTEXT}
 * and the locks of `Thesis\Async\synchronized()`. {main} has no \Fiber, so it gets an object of its own.
 *
 * NEVER store the result in a variable: call fiberKey() again every time the key is needed, in `finally` too.
 * The key is the fiber itself. Kept in a local variable while the fiber is suspended, it is a reference
 * from the fiber's own stack to the fiber: a cycle, so the fiber outlives its last reference, with its scope,
 * its values and its locks, until the cycle collector runs. The tests `valueGoesAwayWithFiber`,
 * `fiberSuspendedInRunGoesAwayWithLastReference` and `fiberSuspendedInsideGoesAwayWithLastReference` catch it.
 *
 * @internal
 */
function fiberKey(): object
{
    /** @var \stdClass */
    static $main = new \stdClass();

    return \Fiber::getCurrent() ?? $main;
}

/**
 * The common part of `Thesis\Async\synchronized()` and `Thesis\Async\synchronizedKey()`: runs $fn with $lock
 * recorded in $locks under $key, so that the fiber's reentrant calls find it, and releases it whatever $fn ends with.
 *
 * @internal
 * @template TKey
 * @template T
 * @param \ArrayAccess<TKey, Lock> $locks the locks the current fiber holds
 * @param TKey $key
 * @param callable(): T $fn
 * @return T
 */
function holding(\ArrayAccess $locks, mixed $key, Lock $lock, callable $fn): mixed
{
    $locks[$key] = $lock;

    try {
        return $fn();
    } finally {
        // Forgotten before the release: if it throws, the fiber must not stay a holder that takes no lock.
        unset($locks[$key]);
        $lock->release();
    }
}

/**
 * `Thesis\Async\some()` and `Thesis\Async\race()`: $function names the caller in the error of too few tasks.
 *
 * @internal
 * @template K of array-key
 * @template T
 * @param non-empty-string $function
 * @param positive-int $count
 * @param array<K, callable(): T> $tasks
 * @return non-empty-array<K, T>
 * @throws \ValueError if there are fewer than $count tasks
 */
function some(string $function, int $count, array $tasks): array
{
    if ($count > \count($tasks)) {
        throw new \ValueError(\sprintf(
            '%s() needs at least %d %s, got %d.',
            $function,
            $count,
            $count === 1 ? 'task' : 'tasks',
            \count($tasks),
        ));
    }

    // Once more than this many have failed, $count successes are no longer possible.
    $tolerated = \count($tasks) - $count;
    $results = [];
    $failure = null;
    $failures = 0;

    group(static function (Group $group) use ($count, $tasks, $tolerated, &$results, &$failure, &$failures) {
        foreach ($tasks as $key => $task) {
            $group->spawn(static function () use (
                $key,
                $task,
                $group,
                $count,
                $tolerated,
                &$results,
                &$failure,
                &$failures,
            ) {
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

                // Once failures are decided, the successes left can no longer reach $count:
                // a late one only lands in $results, which is dropped.
                if (\count($results) === $count) {
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
