<?php

declare(strict_types=1);

namespace Thesis\Async;

use Revolt\EventLoop;
use Revolt\EventLoop\CallbackType;
use Revolt\EventLoop\Driver\StreamSelectDriver;
use Revolt\EventLoop\Driver\TracingDriver;
use Revolt\EventLoop\InvalidCallbackError;
use Revolt\EventLoop\UncaughtThrowable;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Internal\Scope;
use Thesis\Async\Internal\VirtualClock;
use Thesis\Async\Internal\VirtualTimeDriver;
use Thesis\TestoPlugin\VirtualTime;
use Time\Duration;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\never;
use function Thesis\Async\Tests\secondsSince;
use function Thesis\Async\Tests\thrown;
use function Thesis\Time\ms;
use function Thesis\Time\ns;
use function Thesis\Time\sec;

/**
 * Every test already runs on virtual time: the ones of virtualTime() itself nest a virtualTime() in it.
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
        $realStart = hrtime(true);

        delay(sec(3600));

        Assert::same(secondsSince($start), 3600.0);
        Assert::int(hrtime(true) - $realStart)->lessThan(1_000_000_000);
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

    public function repeatedTimerFiresEveryInterval(): void
    {
        $start = Instant::now();
        $fired = [];
        $timer = EventLoop::repeat(2, static function () use ($start, &$fired) {
            $fired[] = secondsSince($start);
        });

        delay(sec(7));
        EventLoop::cancel($timer);

        Assert::same($fired, [2.0, 4.0, 6.0]);
    }

    public function burnCpuMovesClockButTimerFiresAtNextSuspension(): void
    {
        $start = Instant::now();
        $fired = null;
        EventLoop::delay(1, static function () use ($start, &$fired) {
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
        EventLoop::defer(static function () use (&$deferred) {
            $deferred = true;
        });

        $spent = self::outsideVirtualTime(static function () {
            $start = hrtime(true);
            burnCpu(ms(20));

            return hrtime(true) - $start;
        });

        Assert::true($spent >= 20_000_000);
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

    public function clockIsFollowedThroughDecoratedDriver(): void
    {
        $start = Instant::now();
        EventLoop::setDriver(new TracingDriver(EventLoop::getDriver()));

        $exception = thrown(static fn() => timeout(sec(5), never(...)));

        Assert::instanceOf($exception, TimedOut::class);
        Assert::same(secondsSince($start), 5.0);
    }

    public function deadlockFailsAtOnce(): void
    {
        $start = Instant::now();
        $exception = thrown(static fn() => group(static function (Group $group) {
            $group->spawn(never(...));
        }));

        Assert::string($exception->getMessage())->contains('Event loop terminated without resuming');
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

            suspend(static function (Continuation $continuation) use ($reader) {
                $callbackId = EventLoop::onReadable($reader, static function () use ($continuation) {
                    $continuation->resume();
                });

                return static function () use ($callbackId) {
                    EventLoop::cancel($callbackId);
                };
            });

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

    public function runRestoresPreviousDriver(): void
    {
        $driver = EventLoop::getDriver();

        $virtual = virtualTime(static function () {
            delay(sec(1));

            return EventLoop::getDriver();
        });
        thrown(static fn() => virtualTime(static fn() => throw new \RuntimeException()));

        Assert::notSame($virtual, $driver);
        Assert::same(EventLoop::getDriver(), $driver);
    }

    public function runDecoratesGivenDriver(): void
    {
        $driver = new StreamSelectDriver();

        [$callbackId, $identifiers] = virtualTime(fn: static function () use ($driver) {
            $callbackId = EventLoop::defer(static function () {});

            return [$callbackId, $driver->getIdentifiers()];
        }, driver: $driver);

        Assert::same($identifiers, [$callbackId]);
    }

    public function runRejectsCurrentDriver(): void
    {
        $exception = thrown(static fn() => virtualTime(static fn() => null, EventLoop::getDriver()));

        Assert::instanceOf($exception, \LogicException::class);
    }

    public function runRejectsDriverWithCallbacks(): void
    {
        $driver = new StreamSelectDriver();
        $driver->defer(static function () {});

        $exception = thrown(static fn() => virtualTime(static fn() => null, $driver));

        Assert::instanceOf($exception, \LogicException::class);
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

    public function runRestoresDriverAndClockEvenIfStoppingLoopThrows(): void
    {
        $driver = EventLoop::getDriver();
        $start = Instant::now();
        delay(sec(5));
        $returned = false;

        $exception = thrown(static function () use (&$returned) {
            virtualTime(static function () use (&$returned) {
                // Both in one tick: the first resumes {main},
                // the second is left to the tick that virtualTime() finishes.
                suspend(static function (Continuation $continuation) {
                    EventLoop::defer(static function () use ($continuation) {
                        $continuation->resume();
                    });
                    EventLoop::defer(static function () {
                        throw new \RuntimeException('left in the loop');
                    });
                });
                $returned = true;
            });
        });

        Assert::true($returned);
        Assert::instanceOf($exception, UncaughtThrowable::class);
        Assert::instanceOf($exception->getPrevious(), \RuntimeException::class);
        Assert::same([EventLoop::getDriver(), secondsSince($start)], [$driver, 5.0]);

        delay(sec(1));

        Assert::same(secondsSince($start), 6.0);
    }

    public function runDropsWhatIsLeftInLoop(): void
    {
        $fired = false;

        virtualTime(static function () use (&$fired) {
            EventLoop::delay(1, static function () use (&$fired) {
                $fired = true;
            });
        });
        delay(sec(1));

        Assert::false($fired);
        assertNoCallbacksLeft();
    }

    public function runThrowsInFiber(): void
    {
        $driver = EventLoop::getDriver();

        $exception = thrown(static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => virtualTime(static fn() => null));
        }));

        Assert::instanceOf($exception, \LogicException::class);
        Assert::same(EventLoop::getDriver(), $driver);
    }

    public function runRejectsRunningDriver(): void
    {
        $driver = new StreamSelectDriver();
        $exception = null;
        $driver->defer(static function () use ($driver, &$exception) {
            $exception = thrown(static fn() => virtualTime(static fn() => null, $driver));
        });

        $driver->run();

        Assert::instanceOf($exception, \LogicException::class);
    }

    public function enablingCallbackIsActivity(): void
    {
        $start = Instant::now();
        $ran = null;
        $callbackId = EventLoop::defer(static function () use ($start, &$ran) {
            $ran = secondsSince($start);
        });
        EventLoop::disable($callbackId);
        EventLoop::delay(5, static function () {});

        EventLoop::enable($callbackId);
        delay(sec(10));

        Assert::same($ran, 0.0);
    }

    public function callbackLeftForLastTickSeesClockOfItsRun(): void
    {
        $seen = null;

        $inner = virtualTime(static function () use (&$seen) {
            $start = Instant::now();
            burnCpu(sec(100));

            // Both in one tick: the first resumes {main}, the second is left to the tick that virtualTime() finishes.
            suspend(static function (Continuation $continuation) use ($start, &$seen) {
                EventLoop::defer(static function () use ($continuation) {
                    $continuation->resume();
                });
                EventLoop::defer(static function () use ($start, &$seen) {
                    $seen = secondsSince($start);
                });
            });

            return secondsSince($start);
        });

        Assert::same([$inner, $seen], [100.0, 100.0]);
    }

    public function referencingFiringTimerKeepsLoopAlive(): void
    {
        $fired = suspend(static function (Continuation $continuation) {
            $timer = EventLoop::unreference(EventLoop::delay(1, static function () use ($continuation) {
                $continuation->resume(true);
            }));
            burnCpu(sec(1));
            // Runs in the tick in which the due timer is handed to its firing defer, after it is.
            EventLoop::defer(static function () use ($timer) {
                EventLoop::reference($timer);
            });

            return static function () use ($timer) {
                EventLoop::cancel($timer);
            };
        });

        Assert::true($fired);
    }

    public function unreferencingFiringTimerLetsLoopEnd(): void
    {
        $exception = thrown(static fn() => suspend(static function (Continuation $continuation) {
            $timer = EventLoop::delay(1, static function () use ($continuation) {
                $continuation->resume();
            });
            burnCpu(sec(1));
            EventLoop::defer(static function () use ($timer) {
                EventLoop::unreference($timer);
            });

            return static function () use ($timer) {
                EventLoop::cancel($timer);
            };
        }));

        Assert::string($exception->getMessage())->contains('Event loop terminated without resuming');
    }

    public function disabledTimerDoesNotFireUntilEnabledAgain(): void
    {
        $start = Instant::now();
        $fired = [];
        $timer = EventLoop::delay(2, static function () use ($start, &$fired) {
            $fired[] = secondsSince($start);
        });

        Assert::same(EventLoop::disable($timer), $timer);
        EventLoop::disable($timer);
        Assert::false(EventLoop::isEnabled($timer));
        delay(sec(3));
        Assert::same(EventLoop::enable($timer), $timer);
        EventLoop::enable($timer);
        Assert::true(EventLoop::isEnabled($timer));
        delay(sec(3));

        Assert::same($fired, [5.0]);
    }

    public function timerIsDisabledWhileItsFiringIsPending(): void
    {
        $fired = false;
        $timer = EventLoop::delay(1, static function () use (&$fired) {
            $fired = true;
        });
        burnCpu(sec(1));
        EventLoop::defer(static function () use ($timer) {
            EventLoop::disable($timer);
        });

        delay(sec(2));

        Assert::false($fired);
        EventLoop::cancel($timer);
    }

    public function unreferencedTimerDoesNotKeepLoopAlive(): void
    {
        $start = Instant::now();
        $fired = 0;
        $timer = EventLoop::delay(1, static function () use (&$fired) {
            ++$fired;
        });

        Assert::same(EventLoop::unreference($timer), $timer);
        Assert::false(EventLoop::isReferenced($timer));
        $exception = thrown(never(...));

        Assert::string($exception->getMessage())->contains('Event loop terminated without resuming');
        Assert::same([$fired, secondsSince($start)], [0, 0.0]);

        Assert::same(EventLoop::reference($timer), $timer);
        Assert::true(EventLoop::isReferenced($timer));
        delay(sec(1));

        Assert::same($fired, 1);
    }

    public function clockDoesNotJumpToUnreferencedTimerOnceReferencedOneIsGone(): void
    {
        $start = Instant::now();
        EventLoop::unreference(EventLoop::delay(1, static function () {}));
        EventLoop::cancel(EventLoop::delay(1, static function () {}));
        EventLoop::disable(EventLoop::delay(1, static function () {}));

        thrown(never(...));

        Assert::same(secondsSince($start), 0.0);
    }

    public function unreferencingPendingTimerLetsLoopEndAtOnce(): void
    {
        EventLoop::unreference(EventLoop::delay(1, static function () {}));

        Assert::false(self::unreferencedDeferRuns());
    }

    public function disablingPendingTimerLetsLoopEndAtOnce(): void
    {
        $timer = EventLoop::delay(1, static function () {});
        EventLoop::disable($timer);

        Assert::false(self::unreferencedDeferRuns());
        EventLoop::cancel($timer);
    }

    public function timerReferencedAgainKeepsLoopAlive(): void
    {
        $fired = suspend(static function (Continuation $continuation) {
            $timer = EventLoop::unreference(EventLoop::delay(1, static function () use ($continuation) {
                $continuation->resume(true);
            }));
            EventLoop::reference($timer);

            return static function () use ($timer) {
                EventLoop::cancel($timer);
            };
        });

        Assert::true($fired);
    }

    public function dueUnreferencedTimerDoesNotKeepLoopAlive(): void
    {
        $fired = false;
        EventLoop::unreference(EventLoop::delay(1, static function () use (&$fired) {
            $fired = true;
        }));
        burnCpu(sec(1));
        // Referenced: the loop runs a tick, in which the due timer is handed to its firing defer.
        EventLoop::defer(static function () {});

        thrown(never(...));

        Assert::false($fired);
    }

    public function timerDisabledTwiceDoesNotFire(): void
    {
        $fired = false;
        $timer = EventLoop::delay(2, static function () use (&$fired) {
            $fired = true;
        });
        EventLoop::disable($timer);
        EventLoop::enable($timer);
        EventLoop::disable($timer);

        delay(sec(3));

        Assert::false($fired);
        EventLoop::cancel($timer);
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
        $timer = EventLoop::delay(PHP_INT_MAX / 1e9, static function () use (&$fired) {
            $fired = true;
        });

        delay(sec(1));

        Assert::false($fired);
        EventLoop::cancel($timer);
    }

    /**
     * A driver that has run holds suspended fibers of closures bound to it: since php/php-src#24134 the cycle
     * collector keeps it, with its file descriptors, unless virtualTime() releases its fibers.
     */
    public function driverThatHasRunGoesAwayAfterCall(): void
    {
        $driver = new StreamSelectDriver();
        virtualTime(static fn() => delay(sec(1)), driver: $driver);
        $reference = \WeakReference::create($driver);
        unset($driver);
        gc_collect_cycles();

        Assert::null($reference->get());
    }

    public function microtaskQueuedOutsideCallbackOfDriverIsActivity(): void
    {
        /** @var array{resource, resource} $pair */
        $pair = stream_socket_pair(domain: STREAM_PF_UNIX, type: STREAM_SOCK_STREAM, protocol: STREAM_IPPROTO_IP);
        [$reader, $writer] = $pair;
        stream_set_blocking($reader, false);
        // Not a callback of the driver: StreamSelectDriver calls the handler from pcntl_signal_dispatch()
        // in its dispatch, unless the signals are handled asynchronously, as the runner of the tests sets them.
        $asyncSignals = pcntl_async_signals(false);
        pcntl_signal(SIGUSR1, static function () use ($writer) {
            EventLoop::queue(static function () use ($writer) {
                fwrite($writer, 'ping');
            });
        });

        try {
            $elapsed = virtualTime(
                fn: static function () use ($reader) {
                    $watcher = new StreamWatcher($reader);
                    $start = Instant::now();

                    try {
                        return timeout(sec(100), static function () use ($watcher, $start) {
                            // The firing defer of this timer resets the pump: in the next tick,
                            // the microtask of the handler is the only activity.
                            delay(sec(1));
                            posix_kill(posix_getpid(), SIGUSR1);
                            $watcher->readable();

                            return secondsSince($start);
                        });
                    } finally {
                        $watcher->close();
                    }
                },
                driver: new StreamSelectDriver(),
            );
        } finally {
            pcntl_signal(SIGUSR1, SIG_DFL);
            pcntl_async_signals($asyncSignals);
        }

        Assert::same($elapsed, 1.0);
    }

    public function identifiersAreThoseOfCallbacksOfUser(): void
    {
        $timer = EventLoop::delay(1, static function () {});
        $defer = EventLoop::defer(static function () {});

        Assert::same(EventLoop::getIdentifiers(), [$defer, $timer]);

        EventLoop::cancel($timer);
        EventLoop::cancel($defer);
    }

    public function stopStopsInnerDriver(): void
    {
        $driver = new VirtualTimeDriver(new VirtualClock(0), new StreamSelectDriver());
        $ticks = 0;
        $driver->repeat(1, static function () use ($driver, &$ticks) {
            if (++$ticks === 3) {
                $driver->stop();
            }
        });

        $driver->run();

        Assert::same($ticks, 3);
    }

    public function timersHaveTypesOfRevolt(): void
    {
        $delay = EventLoop::delay(1, static function () {});
        $repeat = EventLoop::repeat(1, static function () {});
        $defer = EventLoop::defer(static function () {});

        Assert::same(
            actual: [EventLoop::getType($delay), EventLoop::getType($repeat), EventLoop::getType($defer)],
            expected: [CallbackType::Delay, CallbackType::Repeat, CallbackType::Defer],
        );
        Assert::same(
            actual: [EventLoop::isEnabled($defer), EventLoop::isReferenced(EventLoop::unreference($defer))],
            expected: [true, false],
        );
        Assert::true(EventLoop::isReferenced(EventLoop::reference($defer)));

        EventLoop::cancel($delay);
        EventLoop::cancel($repeat);
        EventLoop::cancel($defer);
        assertNoCallbacksLeft();
    }

    public function negativeDelayIsRejectedAsByRevolt(): void
    {
        $delay = thrown(static fn() => EventLoop::delay(-1, static function () {}));
        $repeat = thrown(static fn() => EventLoop::repeat(-1, static function () {}));

        Assert::same(
            actual: [$delay->getMessage(), $repeat->getMessage()],
            expected: ['Delay must be greater than or equal to zero', 'Interval must be greater than or equal to zero'],
        );
    }

    public function callbackReturningValueIsRejectedAsByRevolt(): void
    {
        /** @var array{resource, resource} $pair */
        $pair = stream_socket_pair(domain: STREAM_PF_UNIX, type: STREAM_SOCK_STREAM, protocol: STREAM_IPPROTO_IP);
        $register = [
            static fn(\Closure $callback) => EventLoop::delay(0, $callback),
            EventLoop::defer(...),
            static fn(\Closure $callback) => EventLoop::onWritable($pair[0], $callback),
        ];

        foreach ($register as $fn) {
            $callbackId = $fn(static fn() => 'value');

            $exception = thrown(never(...));

            Assert::instanceOf($exception, UncaughtThrowable::class);
            Assert::instanceOf($exception->getPrevious(), InvalidCallbackError::class);
            EventLoop::cancel($callbackId);
        }
    }

    public function errorHandlerAndHandleAreInnerDriverOnes(): void
    {
        $driver = new StreamSelectDriver();
        $handler = static function () {};

        [$handle, $errorHandler, $debugInfo] = virtualTime(fn: static function () use ($handler) {
            EventLoop::setErrorHandler($handler);
            EventLoop::delay(1, static function () {});

            return [
                EventLoop::getDriver()->getHandle(),
                EventLoop::getErrorHandler(),
                EventLoop::getDriver()->__debugInfo() + ['clock' => Scope::now() / 1e9],
            ];
        }, driver: $driver);

        Assert::same([$handle, $errorHandler], [$driver->getHandle(), $handler]);
        Assert::same([$debugInfo['timers'] ?? null, $debugInfo['inner'] ?? null], [1, $driver->__debugInfo()]);
        Assert::same($debugInfo['now'] ?? null, $debugInfo['clock']);
    }

    public function cancelledTimersDoNotPileUpInQueue(): void
    {
        $start = Instant::now();
        $fired = [];
        EventLoop::delay(3600, static function () use ($start, &$fired) {
            $fired[] = secondsSince($start);
        });
        $timers = [];

        for ($i = 0; $i < 1000; ++$i) {
            $timers[] = EventLoop::delay(3600, static function () {});
        }

        foreach ($timers as $timer) {
            EventLoop::cancel($timer);
        }

        $driver = EventLoop::getDriver();
        \assert($driver instanceof VirtualTimeDriver);
        $queue = (fn() => $this->queue)->call($driver);

        Assert::int($queue->count())->lessThan(100);

        delay(sec(3600));

        Assert::same($fired, [3600.0]);
    }

    public function queueIsRebuiltOnceOutdatedEntriesAreManyAndMost(): void
    {
        $timers = self::timers(100);
        array_map(EventLoop::cancel(...), \array_slice($timers, 0, 64));

        Assert::same(self::queued(), 36);

        $timers = [...\array_slice($timers, 64), ...self::timers(28)];
        array_map(EventLoop::cancel(...), $timers);

        Assert::same(self::queued(), 0);
    }

    public function queueIsKeptWhileOutdatedEntriesAreFew(): void
    {
        array_map(EventLoop::cancel(...), self::timers(63));

        Assert::same(self::queued(), 63);
    }

    public function queueIsKeptWhileOutdatedEntriesAreNoMoreThanPendingTimers(): void
    {
        $timers = self::timers(128);
        array_map(EventLoop::cancel(...), \array_slice($timers, 0, 64));

        Assert::same(self::queued(), 128);

        array_map(EventLoop::cancel(...), \array_slice($timers, 64));
    }

    public function outdatedEntriesDroppedAtTopAreNoLongerCounted(): void
    {
        array_map(EventLoop::cancel(...), self::timers(10, seconds: 1));
        $timers = self::timers(60, seconds: 10);

        delay(sec(0)); // the pump drops the outdated entries before the first timer pending
        array_map(EventLoop::cancel(...), $timers);

        Assert::same(self::queued(), 60);
    }

    #[VirtualTime]
    public function attributeAndPluginDoNotWrapTwice(): void
    {
        $driver = EventLoop::getDriver();
        \assert($driver instanceof VirtualTimeDriver);

        Assert::false((fn() => $this->inner)->call($driver) instanceof VirtualTimeDriver);
    }

    /**
     * Whether an unreferenced defer runs before the loop ends: only if something else keeps the loop alive for a tick.
     */
    private static function unreferencedDeferRuns(): bool
    {
        $ran = false;
        EventLoop::unreference(EventLoop::defer(static function () use (&$ran) {
            $ran = true;
        }));

        thrown(never(...));

        return $ran;
    }

    /**
     * @param positive-int $count
     * @return list<string>
     */
    private static function timers(int $count, int $seconds = 3600): array
    {
        $timers = [];

        for ($i = 0; $i < $count; ++$i) {
            $timers[] = EventLoop::delay($seconds, static function () {});
        }

        return $timers;
    }

    /**
     * How many entries the queue of the timers of the current driver holds, outdated ones included.
     */
    private static function queued(): int
    {
        $driver = EventLoop::getDriver();
        \assert($driver instanceof VirtualTimeDriver);

        return (fn() => $this->queue->count())->call($driver);
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
