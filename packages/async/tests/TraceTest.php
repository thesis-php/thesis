<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Test;
use Thesis\Async\Trace\Ended;
use Thesis\Async\Trace\Event;
use Thesis\Async\Trace\GroupCreated;
use Thesis\Async\Trace\ScopeCancelled;
use Thesis\Async\Trace\Suspended;
use Thesis\Async\Trace\TaskCreated;
use Thesis\Dispatcher;
use Thesis\SourceLocation\Location;
use function Thesis\Async\Tests\never;
use function Thesis\Async\Tests\thrown;
use function Thesis\Async\Trace\observe;
use function Thesis\Time\sec;

#[Test]
final class TraceTest
{
    public function tracesTaskFromCreationToResult(): void
    {
        $log = self::record(static fn() => group(static function (Group $group) {
            $group->spawn(static function () {
                delay(sec(1));

                return 42;
            });
        }));

        Assert::same($log->lines, [
            '0 #1 group in root',
            '0 #2 task in #1',
            '0 #1 joining',
            '0 #2 started',
            '0 #2 suspended in Thesis\Async\delay',
            '1 #2 resumed',
            '1 #2 returned 42',
            '1 #1 returned null',
        ]);
    }

    public function tracesCancellationByHandle(): void
    {
        $log = self::record(static fn() => group(static function (Group $group) {
            $handle = $group->spawn(static fn() => never());
            delay(sec(1));
            $handle->cancel();
        }));

        Assert::same($log->lines, [
            '0 #1 group in root',
            '0 #2 task in #1',
            '0 #1 suspended in Thesis\Async\delay',
            '0 #2 started',
            '0 #2 suspended in Thesis\Async\suspend',
            '1 #1 resumed',
            '1 #2 cancelled: Handle by #1, reached #2',
            '1 #1 joining',
            '1 #2 resumed by Cancelled',
            '1 #2 threw Cancelled',
            '1 #1 returned null',
        ]);
    }

    public function tracesCancellationOfTasks(): void
    {
        $log = self::record(static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => never());
            $group->cancelTasks();
        }));

        Assert::same($log->lines, [
            '0 #1 group in root',
            '0 #2 task in #1',
            '0 #1 cancelled: CancelTasks by #1, reached #2',
            '0 #1 joining',
            '0 #2 threw Cancelled',
            '0 #1 returned null',
        ]);
    }

    public function tracesDeadlineReachingSubtree(): void
    {
        $log = self::record(static fn() => timeoutOr(sec(2), static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => never());
        })));

        Assert::same($log->lines, [
            '0 #1 timeout 2s in root',
            '0 #2 group in #1',
            '0 #3 task in #2',
            '0 #2 joining',
            '0 #3 started',
            '0 #3 suspended in Thesis\Async\suspend',
            '2 #1 cancelled: Deadline by #1, reached #1 #2 #3',
            '2 #3 resumed by Cancelled',
            '2 #3 threw Cancelled',
            '2 #2 threw Cancelled',
            '2 #1 threw Cancelled',
        ]);
    }

    public function tracesFailureCancellingGroup(): void
    {
        $log = self::record(static fn() => thrown(static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => never());
            $group->spawn(static function () {
                delay(sec(1));

                throw new \RuntimeException('boom');
            });
        })));

        Assert::same($log->lines, [
            '0 #1 group in root',
            '0 #2 task in #1',
            '0 #3 task in #1',
            '0 #1 joining',
            '0 #2 started',
            '0 #2 suspended in Thesis\Async\suspend',
            '0 #3 started',
            '0 #3 suspended in Thesis\Async\delay',
            '1 #3 resumed',
            '1 #3 threw RuntimeException',
            '1 #1 cancelled: Failure by #3, reached #1 #2',
            '1 #2 resumed by Cancelled',
            '1 #2 threw Cancelled',
            '1 #1 threw RuntimeException',
        ]);
    }

    public function tracesTaskCancelledBeforeItStarted(): void
    {
        $log = self::record(static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => 1)->cancel();
        }));

        Assert::same($log->lines, [
            '0 #1 group in root',
            '0 #2 task in #1',
            '0 #2 cancelled: Handle by #1, reached #2',
            '0 #1 joining',
            '0 #2 threw Cancelled',
            '0 #1 returned null',
        ]);
    }

    public function tracesDaemonsCancelledAfterTasks(): void
    {
        $log = self::record(static fn() => group(static function (Group $group) {
            $group->daemon(static fn() => never());
            $group->spawn(static fn() => delay(sec(1)));
        }));

        Assert::same($log->lines, [
            '0 #1 group in root',
            '0 #2 daemon in #1',
            '0 #3 task in #1',
            '0 #1 joining',
            '0 #2 started',
            '0 #2 suspended in Thesis\Async\suspend',
            '0 #3 started',
            '0 #3 suspended in Thesis\Async\delay',
            '1 #3 resumed',
            '1 #3 returned null',
            '1 #1 cancelled: DaemonsAfterTasks by #1, reached #2',
            '1 #2 resumed by Cancelled',
            '1 #2 threw Cancelled',
            '1 #1 returned null',
        ]);
    }

    public function tracesShieldNotReachedByOuterCancellation(): void
    {
        $log = self::record(static fn() => timeoutOr(sec(1), static fn() => shield(static fn() => delay(sec(2)))));

        Assert::same($log->lines, [
            '0 #1 timeout 1s in root',
            '0 #2 shield in #1',
            '0 #2 suspended in Thesis\Async\delay',
            '1 #1 cancelled: Deadline by #1, reached #1',
            '2 #2 resumed',
            '2 #2 returned null',
            '2 #1 returned null',
        ]);
    }

    public function tracesScopeCreatedCancelled(): void
    {
        $log = self::record(static fn() => timeoutOr(sec(0), static fn() => group(static fn() => null)));

        Assert::same($log->lines, [
            '0 #1 timeout 0s in root',
            '0 #1 cancelled: Deadline by #1, reached #1',
            '0 #2 group in #1, cancelled',
            '0 #2 joining',
            '0 #2 returned null',
            '0 #1 returned null',
        ]);
    }

    public function tracesWaitResumedWithError(): void
    {
        $log = self::record(static fn() => thrown(static fn() => shield(static fn() => suspend(
            /** @param Continuation<null> $continuation */
            static function (Continuation $continuation) {
                $continuation->throw(new \RuntimeException());
            },
        ))));

        Assert::same($log->lines, [
            '0 #1 shield in root',
            '0 #1 suspended in Thesis\Async\suspend',
            '0 #1 resumed by RuntimeException',
            '0 #1 threw RuntimeException',
        ]);
    }

    public function locatesCreationAndWaitsInCallingCode(): void
    {
        $groupLine = $spawnLine = $awaitLine = 1;
        $log = self::record(static function () use (&$groupLine, &$spawnLine, &$awaitLine) {
            $groupLine = __LINE__ + 1;
            group(static function (Group $group) use (&$spawnLine, &$awaitLine) {
                $handle = $group->spawn(static fn() => delay(sec(1)));
                $spawnLine = __LINE__ - 1;
                $awaitLine = __LINE__ + 1;
                $handle->await();
            });
        });

        [$group, $task, $wait] = [$log->events[0] ?? null, $log->events[1] ?? null, $log->events[2] ?? null];

        Assert::instanceOf($group, GroupCreated::class);
        Assert::equals($group->location, new Location(__FILE__, $groupLine));
        Assert::instanceOf($task, TaskCreated::class);
        Assert::equals($task->location, new Location(__FILE__, $spawnLine));
        Assert::instanceOf($wait, Suspended::class);
        Assert::equals($wait->caller?->location, new Location(__FILE__, $awaitLine));
        Assert::same($wait->caller?->callee, 'Thesis\Async\Task::await');
    }

    public function givesTaskToObserver(): void
    {
        $task = static fn() => null;
        $log = self::record(static fn() => group(static fn(Group $group) => $group->spawn($task)));

        $created = $log->events[1] ?? null;

        Assert::instanceOf($created, TaskCreated::class);
        Assert::same($created->task, $task);
    }

    public function givesTaskOfUserNotItsWrapperToObserver(): void
    {
        $task = static fn() => null;
        $log = self::record(static function () use ($task) {
            all(['all' => $task]);
            allSettled(['allSettled' => $task]);
            some(1, ['some' => $task]);
            race(['race' => $task]);
        });

        $tasks = array_values(array_map(
            static fn(TaskCreated $event) => $event->task,
            array_filter($log->events, static fn(Event $event) => $event instanceof TaskCreated),
        ));

        Assert::same($tasks, [$task, $task, $task, $task]);
    }

    public function givesCallableOfItemsAsTaskOfWorker(): void
    {
        $fn = static fn(int $item) => delay(sec($item));
        $log = self::record(static function () use ($fn) {
            each([1, 1], concurrency: 2, fn: $fn);
            map([1], concurrency: 1, fn: $fn);
        });

        $tasks = array_values(array_map(
            static fn(TaskCreated $event) => $event->task,
            array_filter($log->events, static fn(Event $event) => $event instanceof TaskCreated),
        ));

        Assert::same($tasks, [$fn, $fn, $fn]);
    }

    public function namesTasksAndDaemonsAsSpawned(): void
    {
        $log = self::record(static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => null, name: 'fetch user');
            $group->daemon(static fn() => null, name: 'heartbeat');
            $group->spawn(static fn() => null);
        }));

        Assert::same(\array_slice($log->lines, 1, 3), [
            '0 #2 task fetch user in #1',
            '0 #3 daemon heartbeat in #1',
            '0 #4 task in #1',
        ]);
    }

    public function namesTasksOfAllAndRaceByTheirKeys(): void
    {
        $log = self::record(static function () {
            all(['user' => static fn() => null, 'orders' => static fn() => null]);
            allSettled(['user' => static fn() => null]);
            some(1, ['eu' => static fn() => null]);
            race([static fn() => null, static fn() => null]);
        });

        $names = array_values(array_map(
            static fn(TaskCreated $event) => $event->name,
            array_filter($log->events, static fn(Event $event) => $event instanceof TaskCreated),
        ));

        Assert::same($names, ['user', 'orders', 'user', 'eu', '0', '1']);
    }

    public function endsTasksOfSomeRaceAndAllSettledWithTheirOwnOutcome(): void
    {
        $log = self::record(static function () {
            some(1, ['a' => static fn() => throw new \RuntimeException(), 'b' => static fn() => 'b']);
            race(['x' => static fn() => 'x']);
            allSettled(['ok' => static fn() => 1, 'failed' => static fn() => throw new \LogicException()]);
        });

        $names = [];
        $outcomes = [];

        foreach ($log->events as $event) {
            if ($event instanceof TaskCreated) {
                $names[$event->scope] = $event->name;
            } elseif ($event instanceof Ended && isset($names[$event->scope])) {
                $outcomes[] = $names[$event->scope] . ': ' . ($event->error === null
                    ? 'returned ' . json_encode($event->value)
                    : 'threw ' . $event->error::class);
            }
        }

        Assert::same($outcomes, [
            'a: threw RuntimeException',
            'b: returned "b"',
            'x: returned "x"',
            'ok: returned 1',
            'failed: threw LogicException',
        ]);
    }

    public function leavesWorkersOfEachAndMapUnnamed(): void
    {
        $log = self::record(static function () {
            each(['a' => 1, 'b' => 2], concurrency: 2, fn: static fn() => delay(sec(1)));
            map(['a' => 1], concurrency: 1, fn: static fn() => null);
        });

        $names = array_values(array_map(
            static fn(TaskCreated $event) => $event->name,
            array_filter($log->events, static fn(Event $event) => $event instanceof TaskCreated),
        ));

        Assert::same($names, [null, null, null]);
    }

    public function namesGroupAsCreated(): void
    {
        $log = self::record(static function () {
            group(static fn(Group $group) => $group->spawn(static fn() => null), name: 'checkout');
            group(static fn() => null);
        });

        $names = array_values(array_map(
            static fn(GroupCreated $event) => $event->name,
            array_filter($log->events, static fn(Event $event) => $event instanceof GroupCreated),
        ));

        Assert::same($names, ['checkout', null]);
        Assert::same($log->lines[0] ?? null, '0 #1 group checkout in root');
    }

    public function tellsGroupsByTheFunctionThatCreatesThem(): void
    {
        $log = self::record(static function () {
            group(static fn() => null);
            all([static fn() => null]);
            allSettled([static fn() => null]);
            some(1, [static fn() => null]);
            race([static fn() => null]);
            each([1], concurrency: 1, fn: static fn() => null);
            map([1], concurrency: 1, fn: static fn() => null);
        });

        $functions = array_values(array_map(
            static fn(GroupCreated $event) => str_replace(__NAMESPACE__ . '\\', '', $event->function),
            array_filter($log->events, static fn(Event $event) => $event instanceof GroupCreated),
        ));

        Assert::same($functions, ['group', 'all', 'allSettled', 'some', 'race', 'each', 'map']);
    }

    public function givesEventsToEveryNestedObserverUntilItsCallReturns(): void
    {
        $outer = new TraceLog();
        $inner = new TraceLog();

        $result = null;
        observe(new Dispatcher([$outer]), static function () use ($inner, &$result) {
            shield(static fn() => null);
            $result = observe(new Dispatcher([$inner]), static fn() => shield(static fn() => 42));
            shield(static fn() => null);
        });
        shield(static fn() => null);

        Assert::same($result, 42);
        Assert::same($inner->lines, ['0 #2 shield in root', '0 #2 returned 42']);
        Assert::same(\count($outer->lines), 6);
    }

    public function givesEventsToConcurrentObserversUntilEachCallReturns(): void
    {
        $first = new TraceLog();
        $second = new TraceLog();

        group(static function (Group $group) use ($first, $second) {
            $group->spawn(static fn() => observe(new Dispatcher([$first]), static fn() => delay(sec(2))));
            $group->spawn(static function () use ($second) {
                delay(sec(1));
                observe(new Dispatcher([$second]), static fn() => delay(sec(2)));
            });
        });
        $after = self::record(static fn() => shield(static fn() => null));

        Assert::same($first->lines, [
            '0 #1 suspended in Thesis\Async\delay',
            '0 #2 started',
            '0 #2 suspended in Thesis\Async\delay',
            '1 #2 resumed',
            '1 #2 suspended in Thesis\Async\delay',
            '2 #1 resumed',
        ]);
        // The numbers of the first observation: it was still running when the second began.
        Assert::same($second->lines, [
            '0 #2 suspended in Thesis\Async\delay',
            '1 #1 resumed',
            '1 #1 returned null',
            '2 #2 resumed',
        ]);
        Assert::same($after->lines, ['0 #1 shield in root', '0 #1 returned null']);
    }

    public function givesListenerOnlyEventsOfItsType(): void
    {
        $lines = [];
        $listener = static function (ScopeCancelled|Ended $event) use (&$lines) {
            $lines[] = '#' . $event->scope . ' ' . new \ReflectionClass($event)->getShortName();
        };

        observe(new Dispatcher([$listener]), static fn() => group(static function (Group $group) {
            $handle = $group->spawn(static fn() => never());
            delay(sec(1));
            $handle->cancel();
        }));

        Assert::same($lines, ['#2 ScopeCancelled', '#2 Ended', '#1 Ended']);
    }

    public function numbersScopesWhetherTheirEventsAreListenedToOrNot(): void
    {
        $scopes = [];
        $listener = static function (Ended $event) use (&$scopes) {
            $scopes[] = $event->scope;
        };

        observe(new Dispatcher([$listener]), static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => shield(static fn() => null));
        }));

        Assert::same($scopes, [3, 2, 1]);
    }

    public function stopsObservingWhenCallThrows(): void
    {
        $log = new TraceLog();

        $error = thrown(static fn() => observe(new Dispatcher([$log]), static fn() => throw new \RuntimeException()));
        shield(static fn() => null);

        Assert::instanceOf($error, \RuntimeException::class);
        Assert::same($log->lines, []);
    }

    /**
     * @param callable(): mixed $fn
     */
    private static function record(callable $fn): TraceLog
    {
        $log = new TraceLog();
        observe(new Dispatcher([$log]), $fn);

        return $log;
    }
}
