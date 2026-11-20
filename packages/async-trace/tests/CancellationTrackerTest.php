<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace;

use Testo\Assert;
use Testo\Test;
use Thesis\Async\Cancelled;
use Thesis\Async\Group;
use Thesis\Async\Instant;
use Thesis\Async\Trace\CancelCause;
use Thesis\Async\Trace\Ended;
use Thesis\Async\Trace\GroupCreated;
use Thesis\Async\Trace\Joining;
use Thesis\Async\Trace\Resumed;
use Thesis\Async\Trace\ScopeCancelled;
use Thesis\Async\Trace\Suspended;
use Thesis\Async\Trace\TaskCreated;
use Thesis\Async\Trace\TaskStarted;
use Thesis\Dispatcher;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\shield;
use function Thesis\Async\timeoutOr;
use function Thesis\Async\Trace\observe;
use function Thesis\Async\Trace\watchdog;
use function Thesis\AsyncTrace\Tests\thrown;
use function Thesis\Time\sec;
use function Thesis\Time\toNs;

#[Test]
final class CancellationTrackerTest
{
    public function reportsDeadlineToEveryScopeItHasReached(): void
    {
        $line = __LINE__ + 1;
        $reports = self::reports(static fn() => timeoutOr(sec(2), static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => delay(sec(5)), name: 'fetch');
        })));

        $timeout = self::at('timeout 2s', $line);
        Assert::same($reports, [
            '#3 ' . self::at("task 'fetch'", $line + 1) . " is cancelled by the deadline of {$timeout}",
            '#2 ' . self::at('group', $line) . " is cancelled by the deadline of {$timeout}",
            "#1 {$timeout} is cancelled by the deadline of {$timeout}",
        ]);
    }

    public function reportsScopesCreatedUnderCancellation(): void
    {
        $line = __LINE__ + 1;
        $reports = self::reports(static fn() => timeoutOr(sec(0), static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => delay(sec(5)));
        })));

        $timeout = self::at('timeout 0s', $line);
        Assert::same($reports, [
            '#3 ' . self::at('task', $line + 1) . " is cancelled by the deadline of {$timeout}",
            '#2 ' . self::at('group', $line) . " is cancelled by the deadline of {$timeout}",
            "#1 {$timeout} is cancelled by the deadline of {$timeout}",
        ]);
    }

    public function reportsTimeoutCreatedUnderCancellation(): void
    {
        $line = __LINE__ + 1;
        $reports = self::reports(static fn() => timeoutOr(sec(0), static fn() => timeoutOr(sec(5), static fn() => delay(sec(1)))));

        $timeout = self::at('timeout 0s', $line);
        Assert::same($reports, [
            '#2 ' . self::at('timeout 5s', $line) . " is cancelled by the deadline of {$timeout}",
            "#1 {$timeout} is cancelled by the deadline of {$timeout}",
        ]);
    }

    public function reportsCallOfTaskCancel(): void
    {
        $line = __LINE__ + 2;
        $reports = self::reports(static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => delay(sec(5)))->cancel();
        }));

        Assert::same($reports, [
            '#2 ' . self::at('task', $line) . ' is cancelled by Thesis\Async\Task::cancel() at ' . __FILE__ . ':'
            . $line . ' in ' . self::at('group', $line - 1),
        ]);
    }

    public function reportsCallOfCancelTasksToTasksSpawnedBeforeAndAfter(): void
    {
        $line = __LINE__ + 2;
        $reports = self::reports(static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => delay(sec(5)));
            $group->cancelTasks();
            $group->spawn(static fn() => delay(sec(5)));
        }));

        $reason = 'Thesis\Async\Group::cancelTasks() at ' . __FILE__ . ':' . ($line + 1)
            . ' in ' . self::at('group', $line - 1);
        Assert::same($reports, [
            '#2 ' . self::at('task', $line) . " is cancelled by {$reason}",
            '#3 ' . self::at('task', $line + 2) . " is cancelled by {$reason}",
        ]);
    }

    public function reportsFailureOfTaskWithItsError(): void
    {
        $line = __LINE__ + 2;
        $reports = self::reports(static fn() => thrown(static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => delay(sec(5)));
            $group->spawn(static fn() => throw new \RuntimeException('boom'), name: 'fetch');
        })));

        Assert::same($reports, [
            '#2 ' . self::at('task', $line) . ' is cancelled by the failure of '
            . self::at("task 'fetch'", $line + 1) . ": RuntimeException 'boom' at " . __FILE__ . ':' . ($line + 1),
        ]);
    }

    public function reportsFailureOfBodyWithItsError(): void
    {
        $line = __LINE__ + 2;
        $reports = self::reports(static fn() => thrown(static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => delay(sec(5)));

            throw new \LogicException('bad');
        }, name: 'checkout')));

        Assert::same($reports, [
            '#2 ' . self::at('task', $line) . ' is cancelled by the failure of '
            . self::at("group 'checkout'", $line - 1) . ": LogicException 'bad' at " . __FILE__ . ':' . ($line + 2),
        ]);
    }

    public function reportsDaemonsCancelledAfterTasks(): void
    {
        $line = __LINE__ + 1;
        $reports = self::reports(static fn() => group(static function (Group $group) {
            $group->daemon(static fn() => delay(sec(5)), name: 'heartbeat');
        }));

        Assert::same($reports, [
            '#2 ' . self::at("daemon 'heartbeat'", $line + 1) . ' is cancelled by the end of the tasks of '
            . self::at('group', $line),
        ]);
    }

    public function reportsCallOfCancelTasksToDaemonsSpawnedAfter(): void
    {
        $line = __LINE__ + 2;
        $reports = self::reports(static fn() => group(static function (Group $group) {
            $group->cancelTasks();
            $group->daemon(static fn() => delay(sec(5)));
        }));

        Assert::same($reports, [
            '#2 ' . self::at('daemon', $line + 1) . ' is cancelled by Thesis\Async\Group::cancelTasks() at '
            . __FILE__ . ':' . $line . ' in ' . self::at('group', $line - 1),
        ]);
    }

    public function reportsDaemonsSpawnedAfterEndOfTasks(): void
    {
        $line = __LINE__ + 1;
        $reports = self::reports(static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => delay(sec(1)));
            $group->daemon(static function () use ($group) {
                try {
                    delay(sec(5));
                } finally {
                    // The tasks are done: it changes nothing for the daemons, cancelled before.
                    $group->cancelTasks();
                    $group->daemon(static fn() => delay(sec(5)), name: 'late');
                }
            });
        }));

        $reason = 'the end of the tasks of ' . self::at('group', $line);
        Assert::same($reports, [
            '#3 ' . self::at('daemon', $line + 2) . " is cancelled by {$reason}",
            '#4 ' . self::at("daemon 'late'", $line + 8) . " is cancelled by {$reason}",
        ]);
    }

    public function reportsFirstCancellationOfGroupToTasksSpawnedAfter(): void
    {
        $line = __LINE__ + 2;
        $reports = self::reports(static fn() => thrown(static fn() => group(static function (Group $group) {
            $group->spawn(static function () {
                try {
                    delay(sec(5));
                } catch (Cancelled) {
                    throw new \RuntimeException('late');
                }
            });
            delay(sec(1));
            $group->cancelTasks();

            try {
                delay(sec(1));
            } finally {
                $group->spawn(static fn() => delay(sec(5)), name: 'late');
                $group->daemon(static fn() => delay(sec(5)), name: 'late');
            }
        })));

        // The failure of the task comes after cancelTasks() and cancels the body, not the tasks.
        $reason = 'Thesis\Async\Group::cancelTasks() at ' . __FILE__ . ':' . ($line + 8)
            . ' in ' . self::at('group', $line - 1);
        Assert::same($reports, [
            '#3 ' . self::at("task 'late'", $line + 13) . " is cancelled by {$reason}",
            '#4 ' . self::at("daemon 'late'", $line + 14) . " is cancelled by {$reason}",
        ]);
    }

    public function namesRootScopeThatHasCancelled(): void
    {
        // A callback of the event loop: outside the fibers, in the root scope.
        $line = __LINE__ + 4;
        $reports = self::reports(static fn() => group(static function (Group $group) {
            $task = $group->spawn(static fn() => delay(sec(5)));
            watchdog(sec(1), static function () use ($task) {
                $task->cancel();
            });
        }));

        Assert::same($reports, [
            '#2 ' . self::at('task', $line - 2) . ' is cancelled by Thesis\Async\Task::cancel() at ' . __FILE__ . ':'
            . $line . ' in the root scope',
        ]);
    }

    public function reportsNothingForScopesNotCancelled(): void
    {
        $reports = self::reports(static fn() => timeoutOr(sec(5), static function () {
            group(static fn(Group $group) => $group->spawn(static fn() => delay(sec(1))));
            shield(static fn() => delay(sec(1)));
            thrown(static fn() => group(static fn() => throw new \RuntimeException()));
        }));

        Assert::same($reports, []);
    }

    public function ignoresScopesItHasNotSeen(): void
    {
        $reports = self::dispatch(static function (Dispatcher $dispatcher) {
            foreach (CancelCause::cases() as $cause) {
                $dispatcher->dispatch(new ScopeCancelled(
                    scope: 5,
                    at: Instant::now(),
                    cause: $cause,
                    by: null,
                    reached: [5],
                    caller: null,
                    error: null,
                ));
            }

            $dispatcher->dispatch(new TaskCreated(
                scope: 6,
                at: Instant::now(),
                group: 5,
                daemon: false,
                name: null,
                task: static fn() => null,
                cancelled: true,
                location: null,
            ));
            $dispatcher->dispatch(new Ended(scope: 6, at: Instant::now(), value: null, error: self::cancelled()));
        });

        Assert::same($reports, []);
    }

    public function namesScopeItHasNotSeenByItsId(): void
    {
        $reports = self::dispatch(static function (Dispatcher $dispatcher) {
            $dispatcher->dispatch(new GroupCreated(
                scope: 2,
                at: Instant::now(),
                parent: null,
                function: 'Thesis\Async\all',
                name: null,
                cancelled: false,
                location: null,
            ));
            $dispatcher->dispatch(new ScopeCancelled(
                scope: 2,
                at: Instant::now(),
                cause: CancelCause::Handle,
                by: 9,
                reached: [2],
                caller: null,
                error: null,
            ));
            $dispatcher->dispatch(new Ended(scope: 2, at: Instant::now(), value: null, error: self::cancelled()));
        });

        Assert::same($reports, ['#2 all is cancelled by Thesis\Async\Task::cancel() in #9']);
    }

    public function givesStructuredReport(): void
    {
        /** @var list<CancelOrigin> $reports */
        $reports = [];
        $listener = static function (CancelOrigin $report) use (&$reports) {
            $reports[] = $report;
        };
        $start = Instant::now();
        $line = __LINE__ + 4;
        $dispatcher = new Dispatcher([new CancellationTracker(), $listener]);
        observe($dispatcher, static fn() => group(static function (Group $group) {
            delay(sec(1));
            $group->spawn(static fn() => delay(sec(5)))->cancel();
        }));

        $report = $reports[0] ?? null;
        Assert::instanceOf($report, CancelOrigin::class);
        Assert::same($report->scope, 2);
        Assert::same($report->by, 1);
        Assert::same($report->cause, CancelCause::Handle);
        Assert::same($report->what, self::at('task', $line));
        Assert::same(toNs($report->at->since($start)), 1_000_000_000);
    }

    public function reportsAfterListenersBeforeItGetTheEnd(): void
    {
        /** @var list<string> $order */
        $order = [];
        $ended = static function (Ended $event) use (&$order) {
            $order[] = 'Ended #' . $event->scope;
        };
        $report = static function (CancelOrigin $report) use (&$order) {
            $order[] = 'CancelOrigin #' . $report->scope;
        };
        observe(
            dispatcher: new Dispatcher([$ended, new CancellationTracker(), $report]),
            fn: static fn() => timeoutOr(sec(1), static fn() => delay(sec(5))),
        );

        Assert::same($order, ['Ended #1', 'CancelOrigin #1']);
    }

    public function listensToNoWaitsAndRuns(): void
    {
        $dispatcher = new Dispatcher([new CancellationTracker()]);

        foreach ([TaskStarted::class, Suspended::class, Resumed::class, Joining::class] as $class) {
            Assert::false($dispatcher->listens($class));
        }
    }

    private static function at(string $what, int $line): string
    {
        return $what . ' at ' . __FILE__ . ':' . $line;
    }

    private static function cancelled(): Cancelled
    {
        $cancelled = null;
        timeoutOr(sec(0), static function () use (&$cancelled) {
            try {
                delay(sec(1));
            } catch (Cancelled $error) {
                $cancelled = $error;
            }
        });

        return $cancelled ?? throw new \LogicException('The deadline has not cancelled the wait.');
    }

    /**
     * @param callable(): mixed $program
     * @return list<string>
     */
    private static function reports(callable $program): array
    {
        return self::dispatch(static fn(Dispatcher $dispatcher) => observe($dispatcher, $program));
    }

    /**
     * @param callable(Dispatcher): mixed $events gives the events to the dispatcher of the tracker
     * @return list<string>
     */
    private static function dispatch(callable $events): array
    {
        /** @var list<string> $reports */
        $reports = [];
        $listener = static function (CancelOrigin $report) use (&$reports) {
            $reports[] = (string) $report;
        };
        $events(new Dispatcher([new CancellationTracker(), $listener]));

        return $reports;
    }
}
