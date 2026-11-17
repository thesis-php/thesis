<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace;

use Testo\Assert;
use Testo\Test;
use Thesis\Async\Group;
use Thesis\Async\Instant;
use Thesis\Async\Trace\ScopeCancelled;
use Thesis\Dispatcher;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\timeout;
use function Thesis\Async\Trace\observe;
use function Thesis\Async\VirtualTime\advance;
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
                advance(ms(120));
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
                advance(ms(50));
                delay(sec(1));
                advance(ms(50));
            });
        }));

        Assert::same($reports, []);
    }

    public function reportsRunFromStartToEndOfTask(): void
    {
        $line = __LINE__ + 2;
        $reports = self::reports(static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => advance(sec(2)));
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
            advance(ms(100));
        }));

        Assert::same($reports, [
            '#1 ' . self::at('group', $line - 1) . ' has run 0.1s without a wait: from Thesis\Async\delay() at '
            . self::file($line + 1) . ' to the join of the group',
        ]);
    }

    public function endsRunOfTaskAtWaitInNestedScope(): void
    {
        $line = __LINE__ + 2;
        $reports = self::reports(static fn() => group(static function (Group $group) {
            $group->spawn(static function () {
                advance(ms(100));
                timeout(sec(5), static fn() => delay(sec(1)));
            });
        }));

        Assert::same($reports, [
            '#2 ' . self::at('task', $line) . ' has run 0.1s without a wait: from the start of the task'
            . ' to Thesis\Async\delay() at ' . self::file($line + 2),
        ]);
    }

    public function givesStructuredReport(): void
    {
        /** @var list<Blocked> $reports */
        $reports = [];
        $listener = static function (Blocked $report) use (&$reports) {
            $reports[] = $report;
        };
        $start = Instant::now();
        $dispatcher = new Dispatcher([new BlockingDetector(threshold: ms(100)), $listener]);
        observe($dispatcher, static fn() => group(static function (Group $group) {
            $group->spawn(static function () {
                delay(sec(1));
                advance(ms(250));
            });
        }));

        $report = $reports[0] ?? null;
        Assert::instanceOf($report, Blocked::class);
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
        $listener = static function (Blocked $report) use (&$reports) {
            $reports[] = (string) $report;
        };
        observe(new Dispatcher([new BlockingDetector(threshold: ms(50)), $listener]), $program);

        return $reports;
    }
}
