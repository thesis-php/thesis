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
use function Thesis\Async\Tests\thrown;
use function Thesis\Async\VirtualTime\advance;
use function Thesis\Async\VirtualTime\run;

/**
 * Every test already runs on virtual time: the ones of run() itself nest a run() in it.
 */
#[Test]
final class VirtualTimeTest
{
    public function clockStartsAtCurrentTimeRoundedUpToWholeSecond(): void
    {
        advance(0.25);
        $outer = now();

        Assert::same(run(static fn() => now() - $outer), 0.75);
    }

    public function timeCapturedBeforeRunStaysValidInIt(): void
    {
        $deadline = now() + 5;

        Assert::same(run(static fn() => $deadline - now()), 5.0);
    }

    public function longDelayIsOverAtOnce(): void
    {
        $start = now();
        $realStart = hrtime(true);

        delay(3_600);

        Assert::same(now() - $start, 3_600.0);
        Assert::int(hrtime(true) - $realStart)->lessThan(1_000_000_000);
    }

    public function timersFireInOrderOfDeadlines(): void
    {
        $start = now();
        $log = [];

        group(static function (Group $group) use ($start, &$log) {
            foreach ([3, 1, 2] as $seconds) {
                $group->spawn(static function () use ($start, $seconds, &$log) {
                    delay($seconds);
                    $log[] = [$seconds, now() - $start];
                });
            }
        });

        Assert::same($log, [[1, 1.0], [2, 2.0], [3, 3.0]]);
    }

    public function codeTakesNoTime(): void
    {
        $start = now();

        for ($i = 0, $sum = 0; $i < 100_000; ++$i) {
            $sum += $i;
        }

        Assert::same(now() - $start, 0.0);
    }

    public function repeatedTimerFiresEveryInterval(): void
    {
        $start = now();
        $fired = [];
        $timer = EventLoop::repeat(2, static function () use ($start, &$fired) {
            $fired[] = now() - $start;
        });

        delay(7);
        EventLoop::cancel($timer);

        Assert::same($fired, [2.0, 4.0, 6.0]);
    }

    public function advanceMovesClockButTimerFiresAtNextSuspension(): void
    {
        $start = now();
        $fired = null;
        EventLoop::delay(1, static function () use ($start, &$fired) {
            $fired = now() - $start;
        });

        advance(2);

        Assert::same([now() - $start, $fired], [2.0, null]);

        delay(0);

        Assert::same($fired, 2.0);
    }

    public function advanceExpiresDeadline(): void
    {
        $start = now();
        $exception = thrown(static fn() => timeout(1, static function () {
            advance(1);
            delay(0);
        }));

        Assert::instanceOf($exception, TimedOut::class);
        Assert::same(now() - $start, 1.0);
    }

    public function advanceRejectsNegativeSeconds(): void
    {
        Expect::exception(\ValueError::class);

        advance(-1);
    }

    public function advanceMovesClockOfInnermostRun(): void
    {
        $start = now();
        $inner = run(static function () {
            $start = now();
            advance(3);

            return now() - $start;
        });

        Assert::same([$inner, now() - $start], [3.0, 0.0]);
    }

    public function advanceThrowsOutsideRun(): void
    {
        // Every test runs inside run(): only another process is outside it.
        // vendor/ is not next to the package in the monorepo, so Composer tells where it is.
        $classLoader = new \ReflectionClass(ClassLoader::class)->getFileName();
        \assert(\is_string($classLoader));
        $code = \sprintf(
            'require %s; try { Thesis\Async\VirtualTime\advance(1); } catch (LogicException $e) { echo $e::class; }',
            var_export(\dirname($classLoader, 2) . '/autoload.php', true),
        );
        $process = proc_open(
            command: [PHP_BINARY, '-r', $code],
            descriptor_spec: [1 => ['pipe', 'w']],
            pipes: $pipes,
        );
        \assert(\is_resource($process) && isset($pipes[1]));
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        Assert::same([$output, proc_close($process)], [\LogicException::class, 0]);
    }

    public function clockIsClockOfLibrary(): void
    {
        $start = now();
        delay(5);

        Assert::same([now() - $start, timeout(10, remaining(...))], [5.0, 10.0]);
    }

    public function clockIsFollowedThroughDecoratedDriver(): void
    {
        $start = now();
        EventLoop::setDriver(new TracingDriver(EventLoop::getDriver()));

        $exception = thrown(static fn() => timeout(5, never(...)));

        Assert::instanceOf($exception, TimedOut::class);
        Assert::same(now() - $start, 5.0);
    }

    public function deadlockFailsAtOnce(): void
    {
        $start = now();
        $exception = thrown(static fn() => group(static function (Group $group) {
            $group->spawn(never(...));
        }));

        Assert::string($exception->getMessage())->contains('Event loop terminated without resuming');
        Assert::same(now() - $start, 0.0);
    }

    public function streamsAreReal(): void
    {
        /** @var array{resource, resource} $pair */
        $pair = stream_socket_pair(
            domain: STREAM_PF_UNIX,
            type: STREAM_SOCK_STREAM,
            protocol: STREAM_IPPROTO_IP,
        );
        [$reader, $writer] = $pair;
        $start = now();

        $read = group(static function (Group $group) use ($reader, $writer, $start) {
            $group->spawn(static function () use ($writer) {
                delay(10);
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

            return [fread($reader, 4), now() - $start];
        });

        Assert::same($read, ['ping', 10.0]);
        assertNoCallbacksLeft();
    }

    public function signalsAreReal(): void
    {
        $start = now();
        $received = group(static function (Group $group) use ($start) {
            $group->spawn(static function () {
                delay(5);
                posix_kill(posix_getpid(), SIGUSR1);
            });

            return [awaitSignal(SIGUSR1), now() - $start];
        });

        Assert::same($received, [SIGUSR1, 5.0]);
        assertNoCallbacksLeft();
    }

    public function runRestoresPreviousDriver(): void
    {
        $driver = EventLoop::getDriver();

        $virtual = run(static function () {
            delay(1);

            return EventLoop::getDriver();
        });
        thrown(static fn() => run(static fn() => throw new \RuntimeException()));

        Assert::notSame($virtual, $driver);
        Assert::same(EventLoop::getDriver(), $driver);
    }

    public function runDecoratesGivenDriver(): void
    {
        $driver = new StreamSelectDriver();

        [$callbackId, $identifiers] = run(static function () use ($driver) {
            $callbackId = EventLoop::defer(static function () {});

            return [$callbackId, $driver->getIdentifiers()];
        }, $driver);

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
        $start = now();
        delay(10);
        $inner = run(static function () {
            $start = now();
            delay(1);

            return now() - $start;
        });

        Assert::same([$inner, now() - $start], [1.0, 10.0]);
    }

    public function runRestoresDriverAndClockEvenIfStoppingLoopThrows(): void
    {
        $driver = EventLoop::getDriver();
        $start = now();
        delay(5);
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
        Assert::same([EventLoop::getDriver(), now() - $start], [$driver, 5.0]);

        delay(1);

        Assert::same(now() - $start, 6.0);
    }

    public function runDropsWhatIsLeftInLoop(): void
    {
        $fired = false;

        run(static function () use (&$fired) {
            EventLoop::delay(1, static function () use (&$fired) {
                $fired = true;
            });
        });
        delay(1);

        Assert::false($fired);
        assertNoCallbacksLeft();
    }

    public function advanceRejectsNan(): void
    {
        $start = now();

        $exception = thrown(static fn() => advance(NAN));

        Assert::instanceOf($exception, \ValueError::class);
        Assert::same(now() - $start, 0.0);
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
        $start = now();
        $ran = null;
        $callbackId = EventLoop::defer(static function () use ($start, &$ran) {
            $ran = now() - $start;
        });
        EventLoop::disable($callbackId);
        EventLoop::delay(5, static function () {});

        EventLoop::enable($callbackId);
        delay(10);

        Assert::same($ran, 0.0);
    }

    public function callbackLeftForLastTickSeesClockOfItsRun(): void
    {
        $seen = null;

        $inner = run(static function () use (&$seen) {
            $start = now();
            advance(100);

            // Both in one tick: the first resumes {main}, the second is left to the tick that run() finishes.
            suspend(static function (Continuation $continuation) use ($start, &$seen) {
                EventLoop::defer(static function () use ($continuation) {
                    $continuation->resume();
                });
                EventLoop::defer(static function () use ($start, &$seen) {
                    $seen = now() - $start;
                });
            });

            return now() - $start;
        });

        Assert::same([$inner, $seen], [100.0, 100.0]);
    }

    public function referencingFiringTimerKeepsLoopAlive(): void
    {
        $fired = suspend(static function (Continuation $continuation) {
            $timer = EventLoop::unreference(EventLoop::delay(1, static function () use ($continuation) {
                $continuation->resume(true);
            }));
            advance(1);
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
            advance(1);
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
        $start = now();
        $fired = [];
        $timer = EventLoop::delay(2, static function () use ($start, &$fired) {
            $fired[] = now() - $start;
        });

        Assert::same(EventLoop::disable($timer), $timer);
        EventLoop::disable($timer);
        Assert::false(EventLoop::isEnabled($timer));
        delay(3);
        Assert::same(EventLoop::enable($timer), $timer);
        EventLoop::enable($timer);
        Assert::true(EventLoop::isEnabled($timer));
        delay(3);

        Assert::same($fired, [5.0]);
    }

    public function timerIsDisabledWhileItsFiringIsPending(): void
    {
        $fired = false;
        $timer = EventLoop::delay(1, static function () use (&$fired) {
            $fired = true;
        });
        advance(1);
        EventLoop::defer(static function () use ($timer) {
            EventLoop::disable($timer);
        });

        delay(2);

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
        delay(1);

        Assert::same($fired, 1);
    }

    public function timersHaveTypesOfRevolt(): void
    {
        $delay = EventLoop::delay(1, static function () {});
        $repeat = EventLoop::repeat(1, static function () {});
        $defer = EventLoop::defer(static function () {});

        Assert::same(
            [EventLoop::getType($delay), EventLoop::getType($repeat), EventLoop::getType($defer)],
            [CallbackType::Delay, CallbackType::Repeat, CallbackType::Defer],
        );
        Assert::same(
            [EventLoop::isEnabled($defer), EventLoop::isReferenced(EventLoop::unreference($defer))],
            [true, false],
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
            [$delay->getMessage(), $repeat->getMessage()],
            ['Delay must be greater than or equal to zero', 'Interval must be greater than or equal to zero'],
        );
    }

    public function callbackReturningValueIsRejectedAsByRevolt(): void
    {
        /** @var array{resource, resource} $pair */
        $pair = stream_socket_pair(
            domain: STREAM_PF_UNIX,
            type: STREAM_SOCK_STREAM,
            protocol: STREAM_IPPROTO_IP,
        );
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

        [$handle, $errorHandler, $debugInfo] = run(static function () use ($handler) {
            EventLoop::setErrorHandler($handler);
            EventLoop::delay(1, static function () {});

            return [
                EventLoop::getDriver()->getHandle(),
                EventLoop::getErrorHandler(),
                EventLoop::getDriver()->__debugInfo(),
            ];
        }, $driver);

        Assert::same([$handle, $errorHandler], [$driver->getHandle(), $handler]);
        Assert::same([$debugInfo['timers'] ?? null, $debugInfo['inner'] ?? null], [1, $driver->__debugInfo()]);
    }

    public function cancelledTimersDoNotPileUpInQueue(): void
    {
        $start = now();
        $fired = [];
        EventLoop::delay(3_600, static function () use ($start, &$fired) {
            $fired[] = now() - $start;
        });
        $timers = [];

        for ($i = 0; $i < 1_000; ++$i) {
            $timers[] = EventLoop::delay(3_600, static function () {});
        }

        foreach ($timers as $timer) {
            EventLoop::cancel($timer);
        }

        $driver = EventLoop::getDriver();
        \assert($driver instanceof VirtualTimeDriver);
        $queue = (fn() => $this->queue)->call($driver);

        Assert::int($queue->count())->lessThan(100);

        delay(3_600);

        Assert::same($fired, [3_600.0]);
    }

    #[VirtualTime]
    public function attributeAndPluginDoNotWrapTwice(): void
    {
        $driver = EventLoop::getDriver();
        \assert($driver instanceof VirtualTimeDriver);

        Assert::false((fn() => $this->inner)->call($driver) instanceof VirtualTimeDriver);
    }
}
