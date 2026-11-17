<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace;

use Revolt\EventLoop;
use Testo\Assert;
use Testo\Test;
use Thesis\Async\Group;
use Thesis\Async\Instant;
use Thesis\Async\Trace\Joining;
use Thesis\Async\Trace\ScopeCancelled;
use Thesis\Async\Trace\Suspended;
use Thesis\Async\Trace\TaskStarted;
use Thesis\Dispatcher;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\timeoutOr;
use function Thesis\Async\Trace\observe;
use function Thesis\Async\VirtualTime\advance;
use function Thesis\Time\ms;
use function Thesis\Time\sec;
use function Thesis\Time\toNs;

#[Test]
final class LongWaitDetectorTest
{
    public function reportsWaitLongerThanThresholdWhileItWaits(): void
    {
        $line = __LINE__ + 2;
        $reports = self::reports(static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => delay(sec(10)), name: 'fetch');
        }));

        Assert::same($reports, [
            '5: #2 ' . self::at("task 'fetch'", $line) . ' has been waiting 5s in Thesis\Async\delay() at '
            . self::file($line),
        ]);
    }

    public function reportsNothingForWaitsShorterThanThreshold(): void
    {
        $reports = self::reports(static fn() => group(static function (Group $group) {
            $group->spawn(static function () {
                delay(ms(4999));
                delay(ms(4999));
            });
        }));

        Assert::same($reports, []);
    }

    public function reportsEachWaitOnceAtItsOwnTime(): void
    {
        $line = __LINE__ + 2;
        $reports = self::reports(static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => delay(sec(30)), name: 'first');
            $group->spawn(static function () {
                delay(sec(2));
                delay(sec(30));
            }, name: 'second');
        }));

        Assert::same($reports, [
            '5: #2 ' . self::at("task 'first'", $line) . ' has been waiting 5s in Thesis\Async\delay() at '
            . self::file($line),
            '7: #3 ' . self::at("task 'second'", $line + 1) . ' has been waiting 5s in Thesis\Async\delay() at '
            . self::file($line + 3),
        ]);
    }

    public function reportsNextWaitOfSameScopeAgain(): void
    {
        $line = __LINE__ + 3;
        $reports = self::reports(static fn() => group(static function (Group $group) {
            $group->spawn(static function () {
                delay(sec(6));
                delay(sec(6));
            });
        }));

        Assert::same($reports, [
            '5: #2 ' . self::at('task', $line - 1) . ' has been waiting 5s in Thesis\Async\delay() at '
            . self::file($line),
            '11: #2 ' . self::at('task', $line - 1) . ' has been waiting 5s in Thesis\Async\delay() at '
            . self::file($line + 1),
        ]);
    }

    public function reportsNothingForWaitEndedByCancellation(): void
    {
        $reports = self::reports(static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => timeoutOr(sec(3), static fn() => delay(sec(10))));
        }));

        Assert::same($reports, []);
    }

    public function reportsWaitOfGroupBody(): void
    {
        $line = __LINE__ + 2;
        $reports = self::reports(static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => null);
            delay(sec(6));
        }));

        Assert::same($reports, [
            '5: #1 ' . self::at('group', $line - 1) . ' has been waiting 5s in Thesis\Async\delay() at '
            . self::file($line + 1),
        ]);
    }

    public function reportsAtOnceWaitOverdueWhenItSeesIt(): void
    {
        $line = __LINE__ + 12;
        /** @var list<string> $reports */
        $reports = [];
        $listener = static function (LongWait $report) use (&$reports) {
            $reports[] = (string) $report;
        };
        // A listener before the detector takes 2s of the virtual clock.
        $slow = static function (Suspended $event) {
            advance(sec(2));
        };
        $dispatcher = new Dispatcher([$slow, new LongWaitDetector(threshold: sec(1)), $listener]);
        observe($dispatcher, static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => delay(sec(10)));
        }));

        Assert::same($reports, [
            '#2 ' . self::at('task', $line) . ' has been waiting 2s in Thesis\Async\delay() at ' . self::file($line),
        ]);
    }

    public function namesScopeItHasNotSeenByItsId(): void
    {
        /** @var list<string> $reports */
        $reports = [];
        $listener = static function (LongWait $report) use (&$reports) {
            $reports[] = (string) $report;
        };
        $dispatcher = new Dispatcher([new LongWaitDetector(threshold: sec(5)), $listener]);

        $dispatcher->dispatch(new Suspended(scope: 9, at: Instant::now(), caller: null));
        delay(sec(6));

        Assert::same($reports, ['#9 #9 has been waiting 5s in a wait of unknown code']);
    }

    public function givesStructuredReport(): void
    {
        /** @var list<LongWait> $reports */
        $reports = [];
        $listener = static function (LongWait $report) use (&$reports) {
            $reports[] = $report;
        };
        $start = Instant::now();
        $dispatcher = new Dispatcher([new LongWaitDetector(threshold: sec(5)), $listener]);
        observe($dispatcher, static fn() => group(static function (Group $group) {
            $group->spawn(static function () {
                delay(sec(1));
                delay(sec(10));
            });
        }));

        $report = $reports[0] ?? null;
        Assert::instanceOf($report, LongWait::class);
        Assert::same($report->scope, 2);
        Assert::same(toNs($report->at->since($start)), 1_000_000_000);
        Assert::same(toNs($report->duration), 5_000_000_000);
    }

    public function waitsOnUnreferencedTimerOnlyWhileSomethingWaits(): void
    {
        $before = EventLoop::getIdentifiers();
        /** @var list<string> $referenced */
        $referenced = [];
        /** @var list<string> $unreferenced */
        $unreferenced = [];
        self::reports(static function () use ($before, &$referenced, &$unreferenced) {
            group(static function (Group $group) use ($before, &$referenced, &$unreferenced) {
                $group->spawn(static fn() => delay(sec(1)), name: 'sleeper');
                $group->spawn(static function () use ($before, &$referenced, &$unreferenced) {
                    delay(ms(500));

                    foreach (array_diff(EventLoop::getIdentifiers(), $before) as $id) {
                        if (EventLoop::isReferenced($id)) {
                            $referenced[] = $id;
                        } else {
                            $unreferenced[] = $id;
                        }
                    }
                });
            });
        });

        // The timer of the sleeper and the one of the detector, due when the sleeper has waited 5s.
        Assert::same(\count($referenced), 1);
        Assert::same(\count($unreferenced), 1);
        Assert::same(EventLoop::getIdentifiers(), $before);
    }

    public function dropsTimerOnceNothingWaits(): void
    {
        $before = EventLoop::getIdentifiers();
        /** @var ?list<string> $left */
        $left = null;
        self::reports(static function () use ($before, &$left) {
            group(static function (Group $group) use ($before, &$left) {
                $group->spawn(static function () use ($before, &$left) {
                    delay(sec(1));
                    $left = array_values(array_diff(EventLoop::getIdentifiers(), $before));
                });
            });
        });

        Assert::same($left, []);
    }

    public function listensToNoCancellationsNorRuns(): void
    {
        $dispatcher = new Dispatcher([new LongWaitDetector(threshold: sec(5))]);

        Assert::false($dispatcher->listens(ScopeCancelled::class));
        Assert::false($dispatcher->listens(TaskStarted::class));
        Assert::false($dispatcher->listens(Joining::class));
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
     * Each report with the whole seconds since the start of the program when it has come.
     *
     * @param callable(): mixed $program
     * @return list<string>
     */
    private static function reports(callable $program): array
    {
        /** @var list<string> $reports */
        $reports = [];
        $start = Instant::now();
        $listener = static function (LongWait $report) use (&$reports, $start) {
            $reports[] = intdiv(toNs(Instant::now()->since($start)), 1_000_000_000) . ': ' . $report;
        };
        observe(new Dispatcher([new LongWaitDetector(threshold: sec(5)), $listener]), $program);

        return $reports;
    }
}
