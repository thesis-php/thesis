<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Internal\Loop;
use Thesis\Async\Internal\Scope;
use Thesis\TestoPlugin\VirtualTime;
use Thesis\Time\Stopwatch;
use Time\Duration;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\later;
use function Thesis\Async\Tests\never;
use function Thesis\Async\Tests\secondsSince;
use function Thesis\Async\Tests\thrown;
use function Thesis\Time\ms;
use function Thesis\Time\ns;
use function Thesis\Time\sec;

/**
 * Every test already runs on virtual time. The tests of virtualTime() start a nested virtualTime() run.
 */
#[Test]
final class VirtualTimeTest
{
    public function clockStartsAtCurrentTimeRoundedUpToWholeSecond(): void
    {
        burnCpu(ms(250));
        $outer = Instant::now();

        Assert::same(virtualTime(static fn() => secondsSince($outer)), 0.75);
    }

    public function timeCapturedBeforeRunStaysValidInIt(): void
    {
        $deadline = Instant::now()->add(sec(5));

        Assert::equals(virtualTime(static fn() => $deadline->since(Instant::now())), sec(5));
    }

    public function longDelayIsOverAtOnce(): void
    {
        $start = Instant::now();
        $watch = Stopwatch::start();

        delay(sec(3600));

        Assert::same(secondsSince($start), 3600.0);
        Assert::true($watch->elapsed() < sec(1));
    }

    public function timersFireInOrderOfDeadlines(): void
    {
        $start = Instant::now();
        $log = [];

        group(static function (Group $group) use ($start, &$log) {
            foreach ([3, 1, 2] as $seconds) {
                $group->spawn(static function () use ($start, $seconds, &$log) {
                    delay(sec($seconds));
                    $log[] = [$seconds, secondsSince($start)];
                });
            }
        });

        Assert::same($log, [[1, 1.0], [2, 2.0], [3, 3.0]]);
    }

    public function codeTakesNoTime(): void
    {
        $start = Instant::now();

        for ($i = 0, $sum = 0; $i < 100_000; ++$i) {
            $sum += $i;
        }

        Assert::same(secondsSince($start), 0.0);
    }

    public function burnCpuMovesClockButTimerFiresAtNextSuspension(): void
    {
        $start = Instant::now();
        $fired = null;
        later(sec(1), static function () use ($start, &$fired) {
            $fired = secondsSince($start);
        });

        burnCpu(sec(2));

        Assert::same([secondsSince($start), $fired], [2.0, null]);

        delay(sec(0));

        Assert::same($fired, 2.0);
    }

    public function burnCpuExpiresDeadline(): void
    {
        $start = Instant::now();
        $exception = thrown(static fn() => timeout(sec(1), static function () {
            burnCpu(sec(1));
            delay(sec(0));
        }));

        Assert::instanceOf($exception, TimedOut::class);
        Assert::same(secondsSince($start), 1.0);
    }

    public function burnCpuRejectsNegativeDuration(): void
    {
        Expect::exception(\ValueError::class);

        burnCpu(sec(-1));
    }

    public function burnCpuSaturatesAtEndOfClock(): void
    {
        $longest = Duration::fromSeconds(9_223_372_035, 999_999_999);

        $now = virtualTime(static function () use ($longest) {
            burnCpu($longest);
            delay(sec(1));

            return Scope::now();
        });

        Assert::same($now, PHP_INT_MAX);
    }

    public function nestedRunStartsAtEndOfClock(): void
    {
        $longest = Duration::fromSeconds(9_223_372_035, 999_999_999);

        $now = virtualTime(static function () use ($longest) {
            burnCpu($longest);

            return virtualTime(Scope::now(...));
        });

        Assert::same($now, PHP_INT_MAX);
    }

    public function burnCpuMovesClockOfInnermostRun(): void
    {
        $start = Instant::now();
        $inner = virtualTime(static function () {
            $start = Instant::now();
            burnCpu(sec(3));

            return secondsSince($start);
        });

        Assert::same([$inner, secondsSince($start)], [3.0, 0.0]);
    }

    public function burnCpuSpinsOnRealClockOutsideVirtualTime(): void
    {
        $deferred = false;
        later(sec(0), static function () use (&$deferred) {
            $deferred = true;
        });

        $spent = self::outsideVirtualTime(static function () {
            $watch = Stopwatch::start();
            burnCpu(ms(20));

            return $watch->elapsed();
        });

        Assert::true($spent >= ms(20));
        Assert::false($deferred);
    }

    public function burnCpuRejectsNegativeDurationOutsideVirtualTime(): void
    {
        $error = self::outsideVirtualTime(static fn() => thrown(static fn() => burnCpu(sec(-1))));

        Assert::same($error::class, \ValueError::class);
    }

    public function clockIsClockOfLibrary(): void
    {
        $start = Instant::now();
        delay(sec(5));

        Assert::equals([secondsSince($start), timeout(sec(10), remaining(...))], [5.0, sec(10)]);
    }

    public function deadlockFailsAtOnce(): void
    {
        $start = Instant::now();
        $exception = thrown(static fn() => group(static function (Group $group) {
            $group->spawn(never(...));
        }));

        Assert::instanceOf($exception, Deadlock::class);
        Assert::same(secondsSince($start), 0.0);
    }

    public function streamsAreReal(): void
    {
        /** @var array{resource, resource} $pair */
        $pair = stream_socket_pair(domain: STREAM_PF_UNIX, type: STREAM_SOCK_STREAM, protocol: STREAM_IPPROTO_IP);
        [$reader, $writer] = $pair;
        $start = Instant::now();

        $read = group(static function (Group $group) use ($reader, $writer, $start) {
            $group->spawn(static function () use ($writer) {
                delay(sec(10));
                fwrite($writer, 'ping');
            });

            $watcher = new StreamWatcher($reader);
            $watcher->readable();
            $watcher->close();

            return [fread($reader, 4), secondsSince($start)];
        });

        Assert::same($read, ['ping', 10.0]);
        assertNoCallbacksLeft();
    }

    public function signalsAreReal(): void
    {
        $start = Instant::now();
        $received = group(static function (Group $group) use ($start) {
            $group->spawn(static function () {
                delay(sec(5));
                posix_kill(posix_getpid(), SIGUSR1);
            });

            return [awaitSignal(SIGUSR1), secondsSince($start)];
        });

        Assert::same($received, [SIGUSR1, 5.0]);
        assertNoCallbacksLeft();
    }

    public function streamReadyInsideProcessIsNotJumpedPastWhileAnotherTimerIsPending(): void
    {
        /** @var array{resource, resource} $pair */
        $pair = stream_socket_pair(domain: STREAM_PF_UNIX, type: STREAM_SOCK_STREAM, protocol: STREAM_IPPROTO_IP);
        [$reader, $writer] = $pair;
        stream_set_blocking($reader, false);
        $watcher = new StreamWatcher($reader);
        $start = Instant::now();

        $elapsed = timeout(sec(100), static function () use ($writer, $watcher, $start) {
            delay(sec(1));
            fwrite($writer, 'ping');
            $watcher->readable();
            delay(sec(1));

            return secondsSince($start);
        });

        $watcher->close();
        Assert::same($elapsed, 2.0);
        assertNoCallbacksLeft();
    }

    public function signalInsideProcessIsNotJumpedPastWhileAnotherTimerIsPending(): void
    {
        $start = Instant::now();

        $elapsed = timeout(sec(100), static fn() => trapSignals(
            signals: [SIGUSR1],
            fn: static function (SignalTrap $trap) use ($start) {
                delay(sec(1));
                posix_kill(posix_getpid(), SIGUSR1);
                $trap->receive();
                delay(sec(1));

                return secondsSince($start);
            },
        ));

        Assert::same($elapsed, 2.0);
        assertNoCallbacksLeft();
    }

    public function seedShufflesFibersReadyInOneTickAndReplaysTheirOrder(): void
    {
        $orders = [];

        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 1] as $seed) {
            $orders[] = self::orderOfFibersReadyInOneTick($seed);
        }

        Assert::same($orders[8], $orders[0]);
        Assert::true(\count(array_unique($orders)) > 1, 'Eight seeds gave one order.');
    }

    public function withoutSeedFibersRunInOrderTheyBecameReady(): void
    {
        Assert::same(self::orderOfFibersReadyInOneTick(seed: null), 'abcd');
    }

    public function runRestoresOuterLoop(): void
    {
        $loop = Loop::get();

        $inner = virtualTime(static function () {
            delay(sec(1));

            return Loop::get();
        });
        thrown(static fn() => virtualTime(static fn() => throw new \RuntimeException()));

        Assert::notSame($inner, $loop);
        Assert::same(Loop::get(), $loop);
    }

    public function nestedRunRestoresOuterClock(): void
    {
        $start = Instant::now();
        delay(sec(10));
        $inner = virtualTime(static function () {
            $start = Instant::now();
            delay(sec(1));

            return secondsSince($start);
        });

        Assert::same([$inner, secondsSince($start)], [1.0, 10.0]);
    }

    public function runThrowsErrorLeftInLoopAndRestoresOuterLoopAndClock(): void
    {
        $loop = Loop::get();
        $start = Instant::now();
        delay(sec(5));
        $returned = false;

        $exception = thrown(static function () use (&$returned) {
            virtualTime(static function () use (&$returned) {
                // Both in one tick: the first resumes the fiber of the run, which ends it,
                // the second throws once the wait of that fiber has already fired.
                suspend(static function (Continuation $continuation) {
                    later(sec(0), static function () use ($continuation) {
                        $continuation->resume();
                    });
                    later(sec(0), static function () {
                        throw new \RuntimeException('left in the loop');
                    });
                });
                $returned = true;
            });
        });

        Assert::true($returned);
        Assert::instanceOf($exception, \RuntimeException::class);
        Assert::same([Loop::get(), secondsSince($start)], [$loop, 5.0]);

        delay(sec(1));

        Assert::same(secondsSince($start), 6.0);
    }

    public function runDropsWhatIsLeftInLoop(): void
    {
        $fired = false;

        virtualTime(static function () use (&$fired) {
            later(sec(1), static function () use (&$fired) {
                $fired = true;
            });
        });
        delay(sec(1));

        Assert::false($fired);
        assertNoCallbacksLeft();
    }

    public function runInTaskBlocksItsFiberUntilInnerLoopEnds(): void
    {
        $start = Instant::now();

        $inner = group(static function (Group $group) {
            $other = $group->spawn(static fn() => delay(sec(1)));
            $inner = $group->spawn(static fn() => virtualTime(static function (): float {
                $start = Instant::now();
                delay(sec(10));

                return secondsSince($start);
            }));
            $other->await();

            return $inner->await();
        });

        Assert::same([$inner, secondsSince($start)], [10.0, 1.0]);
    }

    public function callbackLeftForLastTickSeesClockOfItsRun(): void
    {
        $seen = null;

        $inner = virtualTime(static function () use (&$seen) {
            $start = Instant::now();
            burnCpu(sec(100));

            // Both in one tick: the first resumes the fiber of the run, the second fires before the run ends.
            suspend(static function (Continuation $continuation) use ($start, &$seen) {
                later(sec(0), static function () use ($continuation) {
                    $continuation->resume();
                });
                later(sec(0), static function () use ($start, &$seen) {
                    $seen = secondsSince($start);
                });
            });

            return secondsSince($start);
        });

        Assert::same([$inner, $seen], [100.0, 100.0]);
    }

    public function unreferencedTimerDoesNotKeepLoopAlive(): void
    {
        $start = Instant::now();
        $fired = 0;
        $cancel = later(sec(1), static function () use (&$fired) {
            ++$fired;
        }, referenced: false);

        $exception = thrown(never(...));

        Assert::instanceOf($exception, Deadlock::class);
        Assert::same([$fired, secondsSince($start)], [0, 0.0]);

        delay(sec(1));

        Assert::same($fired, 1);
        $cancel();
    }

    public function clockDoesNotJumpToUnreferencedTimerOnceReferencedOneIsGone(): void
    {
        $start = Instant::now();
        $cancel = later(sec(1), static function () {}, referenced: false);
        later(sec(1), static function () {})();

        thrown(never(...));

        Assert::same(secondsSince($start), 0.0);
        $cancel();
    }

    public function delayOfWholeNanosecondsIsExact(): void
    {
        $start = Instant::now();

        // As float seconds, 15 ns comes back a hair less and 61 ns a hair more.
        delay(ns(15));
        delay(ns(61));

        Assert::equals(Instant::now()->since($start), ns(76));
    }

    public function longestDelayNeverFires(): void
    {
        $fired = false;
        // The longest Duration, past the end of the clock from now: the deadline saturates.
        $cancel = later(sec(9_223_372_035), static function () use (&$fired) {
            $fired = true;
        });

        delay(sec(1));

        Assert::false($fired);
        $cancel();
    }

    #[VirtualTime]
    public function attributeAndPluginDoNotWrapTwice(): void
    {
        // A loop nested in the fiber of another one would have that fiber as its host.
        Assert::null(Loop::get()->host);
    }

    private static function orderOfFibersReadyInOneTick(?int $seed): string
    {
        $order = [];

        virtualTime(static function () use (&$order) {
            group(static function (Group $group) use (&$order) {
                foreach (['a', 'b', 'c', 'd'] as $name) {
                    $group->spawn(static function () use ($name, &$order) {
                        delay(sec(1));
                        $order[] = $name;
                    });
                }
            });
        }, $seed);

        return implode('', $order);
    }

    /**
     * Every test runs inside virtualTime(): outside it is where no clock is bound.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private static function outsideVirtualTime(callable $fn): mixed
    {
        $clock = Scope::$virtualClock;
        Scope::$virtualClock = null;

        try {
            return $fn();
        } finally {
            Scope::$virtualClock = $clock;
        }
    }
}
