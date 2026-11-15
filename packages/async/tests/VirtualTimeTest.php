<?php

declare(strict_types=1);

namespace Thesis\Async;

use Composer\Autoload\ClassLoader;
use Revolt\EventLoop;
use Revolt\EventLoop\CallbackType;
use Revolt\EventLoop\Driver\StreamSelectDriver;
use Revolt\EventLoop\Driver\TracingDriver;
use Revolt\EventLoop\InvalidCallbackError;
use Revolt\EventLoop\UncaughtThrowable;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Internal\VirtualTimeDriver;
use Thesis\Async\Testo\VirtualTime;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\never;
use function Thesis\Async\Tests\secondsSince;
use function Thesis\Async\Tests\thrown;
use function Thesis\Async\VirtualTime\advance;
use function Thesis\Async\VirtualTime\run;
use function Thesis\Time\ms;
use function Thesis\Time\sec;

/**
 * Every test already runs on virtual time: the ones of run() itself nest a run() in it.
 */
#[Test]
final class VirtualTimeTest
{
    public function clockStartsAtCurrentTimeRoundedUpToWholeSecond(): void
    {
        advance(ms(250));
        $outer = Instant::now();

        Assert::same(run(static fn() => secondsSince($outer)), 0.75);
    }

    public function timeCapturedBeforeRunStaysValidInIt(): void
    {
        $deadline = Instant::now()->add(sec(5));

        Assert::equals(run(static fn() => $deadline->since(Instant::now())), sec(5));
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

    public function advanceMovesClockButTimerFiresAtNextSuspension(): void
    {
        $start = Instant::now();
        $fired = null;
        EventLoop::delay(1, static function () use ($start, &$fired) {
            $fired = secondsSince($start);
        });

        advance(sec(2));

        Assert::same([secondsSince($start), $fired], [2.0, null]);

        delay(sec(0));

        Assert::same($fired, 2.0);
    }

    public function advanceExpiresDeadline(): void
    {
        $start = Instant::now();
        $exception = thrown(static fn() => timeout(sec(1), static function () {
            advance(sec(1));
            delay(sec(0));
        }));

        Assert::instanceOf($exception, TimedOut::class);
        Assert::same(secondsSince($start), 1.0);
    }

    public function advanceRejectsNegativeSeconds(): void
    {
        Expect::exception(\ValueError::class);

        advance(sec(-1));
    }

    public function advanceMovesClockOfInnermostRun(): void
    {
        $start = Instant::now();
        $inner = run(static function () {
            $start = Instant::now();
            advance(sec(3));

            return secondsSince($start);
        });

        Assert::same([$inner, secondsSince($start)], [3.0, 0.0]);
    }

    public function advanceThrowsOutsideRun(): void
    {
        // Every test runs inside run(): only another process is outside it.
        // vendor/ is not next to the package in the monorepo, so Composer tells where it is.
        $classLoader = new \ReflectionClass(ClassLoader::class)->getFileName();
        \assert(\is_string($classLoader));
        $code = \sprintf(
            'require %s; try { Thesis\Async\VirtualTime\advance(Thesis\Time\sec(1)); } catch (LogicException $e) { echo $e::class; }',
            var_export(\dirname($classLoader, 2) . '/autoload.php', true),
        );
        $process = proc_open(command: [PHP_BINARY, '-r', $code], descriptor_spec: [1 => ['pipe', 'w']], pipes: $pipes);
        \assert(\is_resource($process) && isset($pipes[1]));
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        Assert::same([$output, proc_close($process)], [\LogicException::class, 0]);
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

    public function runRestoresPreviousDriver(): void
    {
        $driver = EventLoop::getDriver();

        $virtual = run(static function () {
            delay(sec(1));

            return EventLoop::getDriver();
        });
        thrown(static fn() => run(static fn() => throw new \RuntimeException()));

        Assert::notSame($virtual, $driver);
        Assert::same(EventLoop::getDriver(), $driver);
    }

    public function runDecoratesGivenDriver(): void
    {
        $driver = new StreamSelectDriver();

        [$callbackId, $identifiers] = run(fn: static function () use ($driver) {
            $callbackId = EventLoop::defer(static function () {});

            return [$callbackId, $driver->getIdentifiers()];
        }, driver: $driver);

        Assert::same($identifiers, [$callbackId]);
    }

    public function runRejectsCurrentDriver(): void
    {
        $exception = thrown(static fn() => run(static fn() => null, EventLoop::getDriver()));

        Assert::instanceOf($exception, \LogicException::class);
    }

    public function runRejectsDriverWithCallbacks(): void
    {
        $driver = new StreamSelectDriver();
        $driver->defer(static function () {});

        $exception = thrown(static fn() => run(static fn() => null, $driver));

        Assert::instanceOf($exception, \LogicException::class);
    }

    public function nestedRunRestoresOuterClock(): void
    {
        $start = Instant::now();
        delay(sec(10));
        $inner = run(static function () {
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
            run(static function () use (&$returned) {
                // Both in one tick: the first resumes {main}, the second is left to the tick that run() finishes.
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

        run(static function () use (&$fired) {
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
            $group->spawn(static fn() => run(static fn() => null));
        }));

        Assert::instanceOf($exception, \LogicException::class);
        Assert::same(EventLoop::getDriver(), $driver);
    }

    public function runRejectsRunningDriver(): void
    {
        $driver = new StreamSelectDriver();
        $exception = null;
        $driver->defer(static function () use ($driver, &$exception) {
            $exception = thrown(static fn() => run(static fn() => null, $driver));
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

        $inner = run(static function () use (&$seen) {
            $start = Instant::now();
            advance(sec(100));

            // Both in one tick: the first resumes {main}, the second is left to the tick that run() finishes.
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
            advance(sec(1));
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
            advance(sec(1));
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
        advance(sec(1));
        EventLoop::defer(static function () use ($timer) {
            EventLoop::disable($timer);
        });

        delay(sec(2));

        Assert::false($fired);
        EventLoop::cancel($timer);
    }

    public function unreferencedTimerDoesNotKeepLoopAlive(): void
    {
        $fired = 0;
        $timer = EventLoop::delay(1, static function () use (&$fired) {
            ++$fired;
        });

        Assert::same(EventLoop::unreference($timer), $timer);
        Assert::false(EventLoop::isReferenced($timer));
        $exception = thrown(never(...));

        Assert::string($exception->getMessage())->contains('Event loop terminated without resuming');
        Assert::same($fired, 0);

        Assert::same(EventLoop::reference($timer), $timer);
        Assert::true(EventLoop::isReferenced($timer));
        delay(sec(1));

        Assert::same($fired, 1);
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
            // @phpstan-ignore argument.type (the value returned is what is tested)
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

        [$handle, $errorHandler, $debugInfo] = run(fn: static function () use ($handler) {
            EventLoop::setErrorHandler($handler);
            EventLoop::delay(1, static function () {});

            return [
                EventLoop::getDriver()->getHandle(),
                EventLoop::getErrorHandler(),
                EventLoop::getDriver()->__debugInfo(),
            ];
        }, driver: $driver);

        Assert::same([$handle, $errorHandler], [$driver->getHandle(), $handler]);
        Assert::same([$debugInfo['timers'] ?? null, $debugInfo['inner'] ?? null], [1, $driver->__debugInfo()]);
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

    #[VirtualTime]
    public function attributeAndPluginDoNotWrapTwice(): void
    {
        $driver = EventLoop::getDriver();
        \assert($driver instanceof VirtualTimeDriver);

        Assert::false((fn() => $this->inner)->call($driver) instanceof VirtualTimeDriver);
    }
}
