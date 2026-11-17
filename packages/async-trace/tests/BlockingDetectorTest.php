<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace;

use Testo\Assert;
use Testo\Test;
use Thesis\Async\Group;
use Thesis\Async\Instant;
use Thesis\Async\Trace\ScopeCancelled;
use Thesis\Async\Trace\Suspended;
use Thesis\Async\Trace\TaskStarted;
use Thesis\Dispatcher;
use function Thesis\Async\burnCpu;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\shield;
use function Thesis\Async\timeout;
use function Thesis\Async\Trace\observe;
use function Thesis\Time\ms;
use function Thesis\Time\sec;
use function Thesis\Time\toNs;

#[Test]
final class BlockingDetectorTest
{
    public function reportsRunLongerThanThresholdBetweenItsWaits(): void
    {
        $line = __LINE__ + 2;
        $reports = self::reports(static fn() => group(static function (Group $group) {
            $group->spawn(static function () {
                delay(sec(1));
                burnCpu(ms(120));
                delay(sec(1));
            }, name: 'work');
        }));

        Assert::same($reports, [
            '#2 ' . self::at("task 'work'", $line) . ' has run 0.12s without a wait: from Thesis\Async\delay() at '
            . self::file($line + 1) . ' to Thesis\Async\delay() at ' . self::file($line + 3),
        ]);
    }

    public function reportsNothingForRunsUpToThreshold(): void
    {
        $reports = self::reports(static fn() => group(static function (Group $group) {
            $group->spawn(static function () {
                burnCpu(ms(50));
                delay(sec(1));
                burnCpu(ms(50));
            });
        }));

        Assert::same($reports, []);
    }

    public function reportsRunFromStartToEndOfTask(): void
    {
        $line = __LINE__ + 2;
        $reports = self::reports(static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => burnCpu(sec(2)));
        }));

        Assert::same($reports, [
            '#2 ' . self::at('task', $line) . ' has run 2s without a wait: from the start of the task'
            . ' to the end of the task',
        ]);
    }

    public function endsRunOfBodyAtJoin(): void
    {
        $line = __LINE__ + 2;
        $reports = self::reports(static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => delay(sec(1)));
            delay(sec(1));
            burnCpu(ms(100));
        }));

        Assert::same($reports, [
            '#1 ' . self::at('group', $line - 1) . ' has run 0.1s without a wait: from Thesis\Async\delay() at '
            . self::file($line + 1) . ' to the join of the group',
        ]);
    }

    public function reportsRunOfBodyFromStartOfGroupInRoot(): void
    {
        $line = __LINE__ + 2;
        $reports = self::reports(static fn() => group(static function () {
            burnCpu(ms(100));
            delay(sec(1));
        }));

        Assert::same($reports, [
            '#1 ' . self::at('group', $line - 1) . ' has run 0.1s without a wait: from the start of the group'
            . ' to Thesis\Async\delay() at ' . self::file($line + 1),
        ]);
    }

    public function reportsRunOfBodyFromStartOfTimeoutInRoot(): void
    {
        $line = __LINE__ + 2;
        $reports = self::reports(static fn() => timeout(sec(5), static function () {
            burnCpu(ms(100));
            delay(sec(1));
        }));

        Assert::same($reports, [
            '#1 ' . self::at('timeout 5s', $line - 1) . ' has run 0.1s without a wait: from the start of the timeout'
            . ' to Thesis\Async\delay() at ' . self::file($line + 1),
        ]);
    }

    public function reportsRunOfBodyFromStartToEndOfShieldInRoot(): void
    {
        $line = __LINE__ + 2;
        $reports = self::reports(static fn() => shield(static fn() => burnCpu(ms(100))));

        Assert::same($reports, [
            '#1 ' . self::at('shield', $line - 1) . ' has run 0.1s without a wait: from the start of the shield'
            . ' to the end of the shield',
        ]);
    }

    public function keepsRunOfTimeoutInRootThroughNestedGroup(): void
    {
        $line = __LINE__ + 2;
        $reports = self::reports(static fn() => timeout(sec(5), static function () {
            burnCpu(ms(60));
            group(static function () {
                burnCpu(ms(60));
                delay(sec(1));
            });
        }));

        Assert::same($reports, [
            '#1 ' . self::at('timeout 5s', $line - 1) . ' has run 0.12s without a wait: from the start of the timeout'
            . ' to Thesis\Async\delay() at ' . self::file($line + 3),
        ]);
    }

    public function reportsRunOfTaskFromJoinOfNestedGroup(): void
    {
        $line = __LINE__ + 2;
        $reports = self::reports(static fn() => group(static function (Group $group) {
            $group->spawn(static function () {
                group(static fn(Group $nested) => $nested->spawn(static fn() => delay(sec(1))));
                burnCpu(ms(100));
            });
        }));

        Assert::same($reports, [
            '#2 ' . self::at('task', $line) . ' has run 0.1s without a wait: from the join of the group'
            . ' to the end of the task',
        ]);
    }

    public function keepsRunOfTaskThroughNestedGroup(): void
    {
        $line = __LINE__ + 2;
        $reports = self::reports(static fn() => group(static function (Group $group) {
            $group->spawn(static function () {
                burnCpu(ms(60));
                group(static function () {
                    burnCpu(ms(60));
                    delay(sec(1));
                });
            });
        }));

        Assert::same($reports, [
            '#2 ' . self::at('task', $line) . ' has run 0.12s without a wait: from the start of the task'
            . ' to Thesis\Async\delay() at ' . self::file($line + 4),
        ]);
    }

    public function endsRunOfTaskAtWaitInNestedScope(): void
    {
        $line = __LINE__ + 2;
        $reports = self::reports(static fn() => group(static function (Group $group) {
            $group->spawn(static function () {
                burnCpu(ms(100));
                timeout(sec(5), static fn() => delay(sec(1)));
            });
        }));

        Assert::same($reports, [
            '#2 ' . self::at('task', $line) . ' has run 0.1s without a wait: from the start of the task'
            . ' to Thesis\Async\delay() at ' . self::file($line + 2),
        ]);
    }

    public function namesScopeItHasNotSeenByItsId(): void
    {
        /** @var list<string> $reports */
        $reports = [];
        $listener = static function (BlockingRun $report) use (&$reports) {
            $reports[] = (string) $report;
        };
        $dispatcher = new Dispatcher([new BlockingDetector(threshold: ms(50)), $listener]);

        $dispatcher->dispatch(new TaskStarted(scope: 9, at: Instant::now()));
        burnCpu(ms(100));
        $dispatcher->dispatch(new Suspended(scope: 9, at: Instant::now(), caller: null));

        Assert::same($reports, ['#9 #9 has run 0.1s without a wait: from the start of the task to a wait of unknown code']);
    }

    public function givesStructuredReport(): void
    {
        /** @var list<BlockingRun> $reports */
        $reports = [];
        $listener = static function (BlockingRun $report) use (&$reports) {
            $reports[] = $report;
        };
        $start = Instant::now();
        $dispatcher = new Dispatcher([new BlockingDetector(threshold: ms(100)), $listener]);
        observe($dispatcher, static fn() => group(static function (Group $group) {
            $group->spawn(static function () {
                delay(sec(1));
                burnCpu(ms(250));
            });
        }));

        $report = $reports[0] ?? null;
        Assert::instanceOf($report, BlockingRun::class);
        Assert::same($report->scope, 2);
        Assert::same(toNs($report->at->since($start)), 1_000_000_000);
        Assert::same(toNs($report->duration), 250_000_000);
    }

    public function listensToNoCancellations(): void
    {
        $dispatcher = new Dispatcher([new BlockingDetector(threshold: ms(100))]);

        Assert::false($dispatcher->listens(ScopeCancelled::class));
    }

    private static function at(string $what, int $line): string
    {
        return $what . ' at ' . self::file($line);
    }

    private static function file(int $line): string
    {
        return __FILE__ . ':' . $line;
    }

    /**
     * @param callable(): mixed $program
     * @return list<string>
     */
    private static function reports(callable $program): array
    {
        /** @var list<string> $reports */
        $reports = [];
        $listener = static function (BlockingRun $report) use (&$reports) {
            $reports[] = (string) $report;
        };
        observe(new Dispatcher([new BlockingDetector(threshold: ms(50)), $listener]), $program);

        return $reports;
    }
}
