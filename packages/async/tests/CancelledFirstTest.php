<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Test;
use Thesis\Dispatcher;
use function Thesis\Async\Trace\observe;
use function Thesis\Async\VirtualTime\run;
use function Thesis\Time\sec;

/**
 * Every combination of two tasks (how long they work and how long they clean up under a shield), what the group
 * cancels itself at 2 s and at 4 s (`Task::cancel()` of one, `cancelTasks()`, from the body or from a daemon)
 * and a deadline at 5 s, against a model of who cancelled first: `group()` throws `TimedOut` only if
 * the deadline came to a task that the group had not cancelled before. Found the loss of the result of a group
 * whose own cancellations were unwinding when the deadline came.
 *
 * A combination that cannot behave differently from another one is left out: an action at 4 s after `cancelTasks()`
 * or after cancelling the same task at 2 s, and a cleanup of a task that ends before anything cancels it.
 */
#[Test]
final class CancelledFirstTest
{
    private const int DEADLINE = 5;
    private const array WORKS = [1, 3, 7];
    private const array CLEANUPS = [0, 2, 4];

    /**
     * the index of a task to cancel with its handle, `all` for cancelTasks(), null for nothing.
     */
    private const array ACTIONS = [null, 0, 1, 'all'];

    public function groupThrowsOnlyIfDeadlineCameFirst(): void
    {
        Assert::same(self::mismatches(), []);
    }

    /**
     * An observer only observes: the same cases, traced, behave the same, and every trace is whole.
     */
    public function traceChangesNothing(): void
    {
        $checker = new TraceChecker();

        Assert::same(observe(new Dispatcher([$checker]), self::mismatches(...)), []);
        Assert::true($checker->events > 0);
        Assert::same($checker->violations, []);
        Assert::same($checker->unfinished(), []);
    }

    /**
     * @return list<string>
     */
    private static function mismatches(): array
    {
        $mismatches = [];

        foreach (self::cases() as $case) {
            $expected = self::expected(works: $case['works'], actions: $case['actions']);
            $actual = run(static fn() => self::actual(...$case));

            if ($actual !== $expected) {
                $mismatches[] = \sprintf('%s: %s, expected %s', json_encode($case), $actual, $expected);
            }
        }

        return $mismatches;
    }

    /**
     * @return \Generator<array{
     *     works: array{int, int},
     *     cleanups: array{int, int},
     *     actions: array<int, null|0|1|'all'>,
     *     fromDaemon: bool,
     * }>
     */
    private static function cases(): \Generator
    {
        foreach (self::WORKS as $firstWork) {
            foreach (self::CLEANUPS as $firstCleanup) {
                foreach (self::WORKS as $secondWork) {
                    foreach (self::CLEANUPS as $secondCleanup) {
                        foreach (self::ACTIONS as $atTwo) {
                            foreach (self::ACTIONS as $atFour) {
                                if ($atTwo === 'all' && $atFour !== null || $atTwo !== null && $atTwo === $atFour) {
                                    continue;
                                }

                                $actions = [2 => $atTwo, 4 => $atFour];
                                $firstEndsUncancelled = $firstWork < self::cancelledAt(0, $actions);
                                $secondEndsUncancelled = $secondWork < self::cancelledAt(1, $actions);

                                if (
                                    $firstEndsUncancelled && $firstCleanup !== 0
                                    || $secondEndsUncancelled && $secondCleanup !== 0
                                ) {
                                    continue;
                                }

                                foreach ([false, true] as $fromDaemon) {
                                    yield [
                                        'works' => [$firstWork, $secondWork],
                                        'cleanups' => [$firstCleanup, $secondCleanup],
                                        'actions' => $actions,
                                        'fromDaemon' => $fromDaemon,
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @param array{int, int} $works
     * @param array<int, null|0|1|'all'> $actions in the order of their times
     */
    private static function expected(array $works, array $actions): string
    {
        foreach ($works as $task => $work) {
            if ($work > self::DEADLINE && self::cancelledAt($task, $actions) === self::DEADLINE) {
                return 'TimedOut';
            }
        }

        return 'result';
    }

    /**
     * @param 0|1 $task
     * @param array<int, null|0|1|'all'> $actions in the order of their times
     * @return int when the task is cancelled first: by the group, or by the deadline
     */
    private static function cancelledAt(int $task, array $actions): int
    {
        foreach ($actions as $time => $action) {
            if ($action === $task || $action === 'all') {
                return $time;
            }
        }

        return self::DEADLINE;
    }

    /**
     * @param array{int, int} $works
     * @param array{int, int} $cleanups
     * @param array<int, null|0|1|'all'> $actions in the order of their times
     */
    private static function actual(array $works, array $cleanups, array $actions, bool $fromDaemon): string
    {
        try {
            return timeout(sec(self::DEADLINE), static fn() => group(
                static function (Group $group) use ($works, $cleanups, $actions, $fromDaemon) {
                    $handles = [
                        $group->spawn(self::task(work: $works[0], cleanup: $cleanups[0])),
                        $group->spawn(self::task(work: $works[1], cleanup: $cleanups[1])),
                    ];
                    $script = static function () use ($group, $handles, $actions) {
                        $now = 0;

                        foreach ($actions as $time => $action) {
                            delay(sec($time - $now));
                            $now = $time;

                            match ($action) {
                                null => null,
                                'all' => $group->cancelTasks(),
                                default => $handles[$action]->cancel(),
                            };
                        }
                    };

                    if ($fromDaemon) {
                        // Under a shield: cancelTasks() cancels the daemons too, and must not stop the script.
                        $group->daemon(static fn() => shield($script));
                    } else {
                        $script();
                    }

                    return 'result';
                },
            ));
        } catch (TimedOut) {
            return 'TimedOut';
        }
    }

    /**
     * @return callable(): void
     */
    private static function task(int $work, int $cleanup): callable
    {
        return static function () use ($work, $cleanup) {
            try {
                delay(sec($work));
            } catch (Cancelled $cancelled) {
                shield(static fn() => delay(sec($cleanup)));

                throw $cancelled;
            }
        };
    }
}
