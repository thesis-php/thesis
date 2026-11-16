<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Test;
use Thesis\Async\Trace\Ended;
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
