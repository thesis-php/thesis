<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Thesis\Async\Cancelled;
use Thesis\Async\Group;
use function Thesis\Async\group;

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
