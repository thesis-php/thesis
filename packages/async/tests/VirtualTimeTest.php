<?php

declare(strict_types=1);

namespace Thesis\Async;

use Revolt\EventLoop;
use Revolt\EventLoop\Driver\StreamSelectDriver;
use Revolt\EventLoop\Driver\TracingDriver;
use Revolt\EventLoop\UncaughtThrowable;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;
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
    public function clockStartsAtZero(): void
    {
        Assert::same(now(), 0.0);
    }

    public function longDelayIsOverAtOnce(): void
    {
        $start = hrtime(true);

        delay(3_600);

        Assert::same(now(), 3_600.0);
        Assert::int(hrtime(true) - $start)->lessThan(1_000_000_000);
    }

    public function timersFireInOrderOfDeadlines(): void
    {
        $log = [];

        group(static function (Group $group) use (&$log) {
            foreach ([3, 1, 2] as $seconds) {
                $group->spawn(static function () use ($seconds, &$log) {
                    delay($seconds);
                    $log[] = [$seconds, now()];
                });
            }
        });

        Assert::same($log, [[1, 1.0], [2, 2.0], [3, 3.0]]);
    }

    public function codeTakesNoTime(): void
    {
        for ($i = 0, $sum = 0; $i < 100_000; ++$i) {
            $sum += $i;
        }

        Assert::same(now(), 0.0);
    }

    public function repeatedTimerFiresEveryInterval(): void
    {
        $fired = [];
        $timer = EventLoop::repeat(2, static function () use (&$fired) {
            $fired[] = now();
        });

        delay(7);
        EventLoop::cancel($timer);

        Assert::same($fired, [2.0, 4.0, 6.0]);
    }

    public function advanceMovesClockButTimerFiresAtNextSuspension(): void
    {
        $fired = null;
        EventLoop::delay(1, static function () use (&$fired) {
            $fired = now();
        });

        advance(2);

        Assert::same([now(), $fired], [2.0, null]);

        delay(0);

        Assert::same($fired, 2.0);
    }

    public function advanceExpiresDeadline(): void
    {
        $exception = thrown(static fn() => timeout(1, static function () {
            advance(1);
            delay(0);
        }));

        Assert::instanceOf($exception, TimedOut::class);
        Assert::same(now(), 1.0);
    }

    public function advanceRejectsNegativeSeconds(): void
    {
        Expect::exception(\ValueError::class);

        advance(-1);
    }

    public function advanceMovesClockOfInnermostRun(): void
    {
        $times = run(static function () {
            advance(3);

            return now();
        });

        Assert::same([$times, now()], [3.0, 0.0]);
    }

    public function advanceThrowsOutsideRun(): void
    {
        $clock = Internal\Scope::$virtualClock;
        Internal\Scope::$virtualClock = null;

        try {
            $exception = thrown(static fn() => advance(1));
        } finally {
            Internal\Scope::$virtualClock = $clock;
        }

        Assert::instanceOf($exception, \LogicException::class);
    }

    public function clockIsClockOfLibrary(): void
    {
        delay(5);

        Assert::same([now(), timeout(10, remaining(...))], [5.0, 10.0]);
    }

    public function clockIsFollowedThroughDecoratedDriver(): void
    {
        EventLoop::setDriver(new TracingDriver(EventLoop::getDriver()));

        $exception = thrown(static fn() => timeout(5, never(...)));

        Assert::instanceOf($exception, TimedOut::class);
        Assert::same(now(), 5.0);
    }

    public function deadlockFailsAtOnce(): void
    {
        $exception = thrown(static fn() => group(static function (Group $group) {
            $group->spawn(static fn() => suspend(static fn(Continuation $continuation) => static function () {}));
        }));

        Assert::string($exception->getMessage())->contains('Event loop terminated without resuming');
        Assert::same(now(), 0.0);
    }

    public function streamsAreReal(): void
    {
        /** @var array{resource, resource} $pair */
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        [$reader, $writer] = $pair;

        $read = group(static function (Group $group) use ($reader, $writer) {
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

            return [fread($reader, 4), now()];
        });

        Assert::same($read, ['ping', 10.0]);
        assertNoCallbacksLeft();
    }

    public function signalsAreReal(): void
    {
        $received = group(static function (Group $group) {
            $group->spawn(static function () {
                delay(5);
                posix_kill(posix_getpid(), SIGUSR1);
            });

            return [awaitSignal(SIGUSR1), now()];
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
        delay(10);
        $inner = run(static function () {
            delay(1);

            return now();
        });

        Assert::same([$inner, now()], [1.0, 10.0]);
    }

    public function runRestoresDriverAndClockEvenIfStoppingLoopThrows(): void
    {
        $driver = EventLoop::getDriver();
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
        Assert::same([EventLoop::getDriver(), now()], [$driver, 5.0]);

        delay(1);

        Assert::same(now(), 6.0);
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
}
