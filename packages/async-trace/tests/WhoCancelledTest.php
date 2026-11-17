<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace;

use Testo\Assert;
use Testo\Test;
use Thesis\Async\Group;
use Thesis\Async\Instant;
use Thesis\Async\Trace\CancelCause;
use Thesis\Async\Trace\Ended;
use Thesis\Async\Trace\Joining;
use Thesis\Async\Trace\Resumed;
use Thesis\Async\Trace\Suspended;
use Thesis\Async\Trace\TaskStarted;
use Thesis\Dispatcher;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\shield;
use function Thesis\Async\timeoutOr;
use function Thesis\Async\Trace\observe;
use function Thesis\Time\sec;
use function Thesis\Time\toNs;

#[Test]
final class WhoCancelledTest
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
        $reports = self::reports(static fn() => self::thrown(static fn() => group(static function (Group $group) {
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
        $reports = self::reports(static fn() => self::thrown(static fn() => group(static function (Group $group) {
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

    public function reportsNothingForScopesNotCancelled(): void
    {
        $reports = self::reports(static fn() => timeoutOr(sec(5), static function () {
            group(static fn(Group $group) => $group->spawn(static fn() => delay(sec(1))));
            shield(static fn() => delay(sec(1)));
            self::thrown(static fn() => group(static fn() => throw new \RuntimeException()));
        }));

        Assert::same($reports, []);
    }

    public function givesStructuredReport(): void
    {
        /** @var list<CancelledBy> $reports */
        $reports = [];
        $listener = static function (CancelledBy $report) use (&$reports) {
            $reports[] = $report;
        };
        $start = Instant::now();
        $line = __LINE__ + 4;
        $dispatcher = new Dispatcher([new WhoCancelled(), $listener]);
        observe($dispatcher, static fn() => group(static function (Group $group) {
            delay(sec(1));
            $group->spawn(static fn() => delay(sec(5)))->cancel();
        }));

        $report = $reports[0] ?? null;
        Assert::instanceOf($report, CancelledBy::class);
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
        $report = static function (CancelledBy $report) use (&$order) {
            $order[] = 'CancelledBy #' . $report->scope;
        };
        observe(
            dispatcher: new Dispatcher([$ended, new WhoCancelled(), $report]),
            fn: static fn() => timeoutOr(sec(1), static fn() => delay(sec(5))),
        );

        Assert::same($order, ['Ended #1', 'CancelledBy #1']);
    }

    public function listensToNoWaitsAndRuns(): void
    {
        $dispatcher = new Dispatcher([new WhoCancelled()]);

        foreach ([TaskStarted::class, Suspended::class, Resumed::class, Joining::class] as $class) {
            Assert::false($dispatcher->listens($class));
        }
    }

    private static function at(string $what, int $line): string
    {
        return $what . ' at ' . __FILE__ . ':' . $line;
    }

    /**
     * @param callable(): mixed $fn
     */
    private static function thrown(callable $fn): ?\Throwable
    {
        try {
            $fn();
        } catch (\Throwable $error) {
            return $error;
        }

        return null;
    }

    /**
     * @param callable(): mixed $program
     * @return list<string>
     */
    private static function reports(callable $program): array
    {
        /** @var list<string> $reports */
        $reports = [];
        $listener = static function (CancelledBy $report) use (&$reports) {
            $reports[] = (string) $report;
        };
        observe(new Dispatcher([new WhoCancelled(), $listener]), $program);

        return $reports;
    }
}
