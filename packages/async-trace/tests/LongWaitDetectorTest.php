<?php

declare(strict_types=1);

namespace Thesis\AsyncTrace;

use Testo\Assert;
use Testo\Test;
use Thesis\Async\Deadlock;
use Thesis\Async\Deferred;
use Thesis\Async\Group;
use Thesis\Async\Instant;
use Thesis\Async\Internal\Loop;
use Thesis\Async\Trace\Joining;
use Thesis\Async\Trace\ScopeCancelled;
use Thesis\Async\Trace\Suspended;
use Thesis\Async\Trace\TaskStarted;
use Thesis\Dispatcher;
use function Thesis\Async\burnCpu;
use function Thesis\Async\checkpoint;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\timeout;
use function Thesis\Async\timeoutOr;
use function Thesis\Async\Trace\observe;
use function Thesis\Async\virtualTime;
use function Thesis\Time\ms;
use function Thesis\Time\sec;
use function Thesis\Time\toNs;

#[Test]
final class LongWaitDetectorTest
{
    public function duplicateObservationLeavesNoTimerAfterResume(): void
    {
        $reports = [];
        $dispatcher = new Dispatcher([
            new LongWaitDetector(sec(2)),
            static function (LongWait $report) use (&$reports) {
                $reports[] = $report->scope;
            },
        ]);
        observe($dispatcher, static function () use ($dispatcher) {
            observe($dispatcher, static fn() => group(static fn() => delay(sec(1))));
        });
        delay(sec(3));
        Assert::same($reports, []);
    }

    public function detachingWhileTaskWaitsCancelsItsWatchdog(): void
    {
        $reports = [];
        $dispatcher = new Dispatcher([
            new LongWaitDetector(sec(2)),
            static function (LongWait $report) use (&$reports) {
                $reports[] = $report->scope;
            },
        ]);
        group(static function (Group $group) use ($dispatcher) {
            $task = observe($dispatcher, static function () use ($group) {
                $task = $group->spawn(static fn() => delay(sec(1)));
                checkpoint();

                return $task;
            });
            timeout(sec(2), $task->await(...));
            delay(sec(3));
        });
        Assert::same($reports, []);
    }

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
            burnCpu(sec(2));
        };
        $dispatcher = new Dispatcher([$slow, new LongWaitDetector(threshold: sec(1)), $listener]);
        observe($dispatcher, static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => delay(sec(10)));
        }));

        Assert::same($reports, [
            '#2 ' . self::at('task', $line) . ' has been waiting 2s in Thesis\Async\delay() at ' . self::file($line),
        ]);
    }

    public function reportsWaitOfNestedLoopWhileWaitOfOuterOneIsNotDue(): void
    {
        /** @var list<string> $reports */
        $reports = [];
        $listener = static function (LongWait $report) use (&$reports) {
            $reports[] = $report->what;
        };
        $inner = static function (Group $group) {
            $group->spawn(static fn() => delay(sec(3600)), name: 'inner');
        };
        observe(new Dispatcher([new LongWaitDetector(threshold: sec(5)), $listener]), static fn() => group(
            static function (Group $group) use ($inner) {
                $group->spawn(static fn() => delay(sec(1)), name: 'outer');
                $group->spawn(static fn() => virtualTime(static fn() => group($inner)));
            },
        ));

        // Without " at <file>:<line>".
        Assert::same(array_map(static fn(string $what) => explode(' at ', $what)[0], $reports), ["task 'inner'"]);
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

    public function timerKeepsNothingFromDeadlock(): void
    {
        $start = Instant::now();
        $deadlock = null;

        // Only the timer of the detector, due when the task has waited 5s, could wake anything.
        $reports = self::reports(static function () use (&$deadlock) {
            try {
                group(static function (Group $group) {
                    $group->spawn(static fn() => new Deferred()->await());
                });
            } catch (Deadlock $exception) {
                $deadlock = $exception;
            }
        });

        Assert::instanceOf($deadlock, Deadlock::class);
        Assert::same([$reports, toNs(Instant::now()->since($start))], [[], 0]);
    }

    public function dropsTimerOnceNothingWaits(): void
    {
        /** @var ?bool $idle */
        $idle = null;
        self::reports(static function () use (&$idle) {
            group(static function (Group $group) use (&$idle) {
                $group->spawn(static function () use (&$idle) {
                    delay(sec(1));
                    $idle = Loop::get()->isIdle;
                });
            });
        });

        Assert::true($idle);
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
