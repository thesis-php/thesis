<?php

declare(strict_types=1);

namespace Thesis\Async\Internal;

use Testo\Assert;
use Testo\Test;
use Thesis\Async\Deadlock;
use function Thesis\Async\Tests\thrown;
use function Thesis\Time\ms;
use function Thesis\Time\sec;
use function Thesis\Time\toNs;

#[Test]
final class LoopTest
{
    public function runReturnsWhatFiberReturns(): void
    {
        $loop = self::loop();

        Assert::same($loop->run(static fn() => 'result'), 'result');
        Assert::null($loop->main);
    }

    public function runRethrowsWhatFiberThrows(): void
    {
        $loop = self::loop();

        $exception = thrown(static fn() => $loop->run(static function () {
            throw new \RuntimeException('failed');
        }));

        Assert::same($exception->getMessage(), 'failed');
    }

    public function runIsRefusedInFiber(): void
    {
        $loop = self::loop();
        $fiber = new \Fiber(static fn() => thrown(static fn() => $loop->run(static fn() => null)));
        $fiber->start();

        Assert::instanceOf($fiber->getReturn(), \LogicException::class);
    }

    public function runIsRefusedWhileRunning(): void
    {
        $loop = self::loop();
        $exception = null;

        $loop->run(static function () use ($loop, &$exception) {
            $loop->queue(static function () use ($loop, &$exception) {
                $exception = thrown(static fn() => $loop->run(static fn() => null));
            });
            self::yield($loop);
        });

        Assert::instanceOf($exception, \LogicException::class);
    }

    public function waitInMainIsRefused(): void
    {
        $loop = self::loop();
        $exception = null;

        $loop->run(static function () use ($loop, &$exception) {
            $loop->queue(static function () use (&$exception) {
                $exception = thrown(static fn() => new ContinuationImpl()->park());
            });
            self::yield($loop);
        });

        Assert::instanceOf($exception, \LogicException::class);
        Assert::same($exception->getMessage(), 'A wait in {main} cannot be resumed: wrap the code in group().');
    }

    public function fibersReadyAtStartOfTickRunInOrderAndWhatTheyWakeWaitsForNextTick(): void
    {
        $loop = self::loop();
        $log = [];

        $loop->run(static function () use ($loop, &$log) {
            $a = new ContinuationImpl();
            $b = new ContinuationImpl();
            $loop->start(static function () use ($loop, $a, &$log) {
                $a->park();
                $log[] = 'a ' . $loop->tick;
            });
            $loop->start(static function () use ($loop, $a, $b, &$log) {
                $b->park();
                $log[] = 'b ' . $loop->tick;
                $a->resume();
            });
            self::yield($loop);
            $b->resume();
            self::yield($loop);
            self::yield($loop);
        });

        Assert::same($log, ['b 3', 'a 4']);
    }

    public function microtasksRunAfterEachFiberBeforeNextOne(): void
    {
        $loop = self::loop();
        $log = [];

        $loop->run(static function () use ($loop, &$log) {
            foreach (['a', 'b'] as $name) {
                $loop->start(static function () use ($loop, $name, &$log) {
                    self::yield($loop);
                    $log[] = $name;
                    $loop->queue(static function () use ($name, &$log) {
                        $log[] = 'microtask of ' . $name;
                    });
                });
            }

            self::yield($loop);
            self::yield($loop);
        });

        Assert::same($log, ['a', 'microtask of a', 'b', 'microtask of b']);
    }

    public function fiberStartsAfterCurrentOneSuspends(): void
    {
        $loop = self::loop();
        $log = [];

        $loop->run(static function () use ($loop, &$log) {
            $loop->start(static function () use (&$log) {
                $log[] = 'started';
            });
            $log[] = 'before the wait';
            self::yield($loop);
        });

        Assert::same($log, ['before the wait', 'started']);
    }

    public function pingPongDoesNotStarveTimers(): void
    {
        // The real clock: a virtual one never moves while fibers are ready.
        $loop = new Loop();
        $rounds = 0;

        $loop->run(static function () use ($loop, &$rounds) {
            $expired = false;
            $timer = new ContinuationImpl();
            $loop->delay(toNs(ms(1)), $timer);
            $loop->start(static function () use ($timer, &$expired) {
                $timer->park();
                $expired = true;
            });

            while (!$expired) {
                self::yield($loop);
                ++$rounds;
            }
        });

        Assert::true($rounds > 0);
    }

    public function timerWakesOnceItsTimeHasPassed(): void
    {
        $clock = new VirtualClock(0);
        $loop = new Loop($clock);

        $loop->run(static function () use ($loop) {
            $timer = new ContinuationImpl();
            $loop->delay(toNs(sec(5)), $timer);

            Assert::true($timer->park());
        });

        Assert::same($clock->now, toNs(sec(5)));
    }

    public function timersDueAtOnceFireInOrderOfCreation(): void
    {
        $loop = self::loop();
        $log = [];

        $loop->run(static function () use ($loop, &$log) {
            foreach (['a', 'b', 'c'] as $name) {
                $timer = new ContinuationImpl();
                $loop->delay(toNs(sec(1)), $timer);
                $loop->start(static function () use ($timer, $name, &$log) {
                    $timer->park();
                    $log[] = $name;
                });
            }

            $last = new ContinuationImpl();
            $loop->delay(toNs(sec(2)), $last);
            $last->park();
        });

        Assert::same($log, ['a', 'b', 'c']);
    }

    public function cancelledTimerNeverWakes(): void
    {
        $clock = new VirtualClock(0);
        $loop = new Loop($clock);
        $cancelled = new ContinuationImpl();

        $loop->run(static function () use ($loop, $cancelled) {
            $loop->cancel($loop->delay(toNs(sec(1)), $cancelled));
            $timer = new ContinuationImpl();
            $loop->delay(toNs(sec(2)), $timer);
            $timer->park();
        });

        Assert::true($cancelled->resume(), 'The cancelled timer has fired its waiter.');
        Assert::same($clock->now, toNs(sec(2)));
    }

    public function cancelOfFiredTimerIsNoop(): void
    {
        $loop = self::loop();

        $loop->run(static function () use ($loop) {
            $continuation = new ContinuationImpl();
            $timer = $loop->delay(toNs(sec(1)), $continuation);
            $continuation->park();
            $loop->cancel($timer);
            $loop->cancel($timer);

            Assert::same(self::timers($loop), [0, 0, 0]);
        });
    }

    public function cancelledTimersDoNotPileUp(): void
    {
        $loop = self::loop();

        $loop->run(static function () use ($loop) {
            $kept = $loop->delay(toNs(sec(100)), new ContinuationImpl());

            for ($i = 0; $i < 1000; ++$i) {
                $loop->cancel($loop->delay(toNs(sec(10)), new ContinuationImpl()));
            }

            [$pending, , $outdated] = self::timers($loop);
            Assert::same($pending, 1);
            Assert::true($outdated < 64, 'The heap has kept the cancelled timers.');
            $loop->cancel($kept);
        });
    }

    public function timerFarAwaySaturates(): void
    {
        $clock = new VirtualClock(PHP_INT_MAX - toNs(sec(1)));
        $loop = new Loop($clock);

        $loop->run(static function () use ($loop) {
            $far = $loop->delay(toNs(sec(10)), new ContinuationImpl());

            Assert::same($far->expiration, PHP_INT_MAX);

            $near = new ContinuationImpl();
            $loop->delay(toNs(ms(500)), $near);
            $near->park();
            $loop->cancel($far);
        });

        Assert::same($clock->now, PHP_INT_MAX - toNs(sec(1)) + toNs(ms(500)));
    }

    public function timerFarAwayOnRealClockIsWaitedForUntilNearerOne(): void
    {
        $loop = new Loop();
        $far = new ContinuationImpl();

        $loop->run(static function () use ($loop, $far) {
            $timer = $loop->delay(PHP_INT_MAX, $far);
            $near = new ContinuationImpl();
            $loop->delay(toNs(ms(1)), $near);
            $near->park();
            $loop->cancel($timer);
        });

        Assert::true($far->resume(), 'The far timer has fired.');
    }

    public function unreferencedTimerIsNotWaitedFor(): void
    {
        $loop = self::loop();

        $exception = thrown(static fn() => $loop->run(static function () use ($loop) {
            $continuation = new ContinuationImpl();
            $loop->delay(toNs(sec(1)), $continuation, referenced: false);
            $continuation->park();
        }));

        Assert::instanceOf($exception, Deadlock::class);
    }

    public function clockJumpsToUnreferencedTimerWhileReferencedOneWaits(): void
    {
        $clock = new VirtualClock(0);
        $loop = new Loop($clock);
        $fired = false;

        $loop->run(static function () use ($loop, &$fired) {
            $unreferenced = new ContinuationImpl();
            $loop->delay(toNs(sec(1)), $unreferenced, referenced: false);
            $loop->start(static function () use ($unreferenced, &$fired) {
                $unreferenced->park();
                $fired = true;
            });
            $timer = new ContinuationImpl();
            $loop->delay(toNs(sec(2)), $timer);
            $timer->park();
        });

        Assert::true($fired);
        Assert::same($clock->now, toNs(sec(2)));
    }

    public function deadlockIsThrownIntoWaitOfMain(): void
    {
        $loop = self::loop();

        $exception = thrown(static fn() => $loop->run(static function () use ($loop) {
            $loop->start(static fn() => new ContinuationImpl()->park());
            new ContinuationImpl()->park();
        }));

        Assert::instanceOf($exception, Deadlock::class);
        $lines = explode("\n", $exception->getMessage());
        Assert::same(\count($lines), 3);
        Assert::same(
            $lines[0],
            'Deadlock: the fibers wait, and no timer, stream or signal can wake them. Suspended at:',
        );
        Assert::true(str_contains($lines[1] ?? '', 'LoopTest.php:'), $lines[1] ?? '');
    }

    public function mainGoesOnAfterDeadlockItHasCaught(): void
    {
        $loop = self::loop();

        $result = $loop->run(static function () {
            thrown(static fn() => new ContinuationImpl()->park());

            return 'went on';
        });

        Assert::same($result, 'went on');
    }

    public function errorEscapingToLoopIsThrownIntoWaitOfMain(): void
    {
        $loop = self::loop();

        $exception = thrown(static fn() => $loop->run(static function () use ($loop) {
            $loop->queue(static function () {
                throw new \RuntimeException('escaped');
            });
            $timer = new ContinuationImpl();
            $loop->delay(toNs(sec(1)), $timer);
            $timer->park();
        }));

        Assert::same($exception->getMessage(), 'escaped');
    }

    public function errorEscapingToLoopWaitsForNextWaitOfMainIfItsWaitHasFired(): void
    {
        $loop = self::loop();
        $values = [];

        $exception = thrown(static function () use ($loop, &$values) {
            $loop->run(static function () use ($loop, &$values) {
                $continuation = new ContinuationImpl();
                $loop->start(static function () use ($loop, $continuation) {
                    $continuation->resume('value');
                    $loop->queue(static function () {
                        throw new \RuntimeException('escaped');
                    });
                });
                $values[] = $continuation->park();
                $values[] = self::yield($loop);
            });
        });

        Assert::same($exception->getMessage(), 'escaped');
        Assert::same($values, ['value']);
    }

    public function errorOfOtherFiberIsThrownIntoWaitOfMain(): void
    {
        $loop = self::loop();

        $exception = thrown(static fn() => $loop->run(static function () use ($loop) {
            $loop->start(static function () {
                throw new \RuntimeException('of a fiber');
            });
            new ContinuationImpl()->park();
        }));

        Assert::same($exception->getMessage(), 'of a fiber');
    }

    public function errorLeftWhenMainEndsIsThrownFromRun(): void
    {
        $loop = self::loop();

        $exception = thrown(static fn() => $loop->run(static function () use ($loop) {
            $loop->queue(static function () {
                throw new \RuntimeException('escaped');
            });
        }));

        Assert::same($exception->getMessage(), 'escaped');
    }

    public function readerWakesOnceBytesHaveCome(): void
    {
        [$reader, $writer] = self::pair();
        $loop = self::loop();

        $loop->run(static function () use ($loop, $reader, $writer) {
            $continuation = new ContinuationImpl();
            $loop->watch($reader, $continuation, write: false);
            $loop->start(static function () use ($loop, $writer) {
                self::yield($loop);
                fwrite($writer, 'ping');
            });

            Assert::true($continuation->park());
            Assert::same(fread($reader, 4), 'ping');
            self::yield($loop);
        });

        Assert::same(self::streams($loop), [[], 0]);
    }

    public function readersAndWritersOfOneStreamShareWatcher(): void
    {
        [$reader, $writer] = self::pair();
        $loop = self::loop();
        $log = [];

        $loop->run(static function () use ($loop, $reader, $writer, &$log) {
            foreach (['reader 1' => false, 'reader 2' => false, 'writer' => true] as $name => $write) {
                $continuation = new ContinuationImpl();
                $loop->watch($reader, $continuation, write: $write);
                $loop->start(static function () use ($continuation, $name, &$log) {
                    $continuation->park();
                    $log[] = $name;
                });
            }

            self::yield($loop);
            fwrite($writer, 'ping');
            self::yield($loop);
            self::yield($loop);
        });

        sort($log);
        Assert::same($log, ['reader 1', 'reader 2', 'writer']);
    }

    public function unwatchedWaiterIsNotWoken(): void
    {
        [$reader, $writer] = self::pair();
        $loop = self::loop();
        $unwatched = new ContinuationImpl();

        $loop->run(static function () use ($loop, $reader, $writer, $unwatched) {
            $loop->watch($reader, $unwatched, write: false);
            $loop->unwatch($reader, $unwatched, write: false);
            $loop->unwatch($reader, $unwatched, write: false);
            $continuation = new ContinuationImpl();
            $loop->watch($reader, $continuation, write: false);
            fwrite($writer, 'ping');
            $continuation->park();
        });

        Assert::true($unwatched->resume(), 'The unwatched waiter has been woken.');
    }

    public function streamClosedByOwnerAfterItsWaitsEnded(): void
    {
        [$reader] = self::pair();
        $loop = self::loop();

        $loop->run(static function () use ($loop, $reader) {
            $continuation = new ContinuationImpl();
            $loop->watch($reader, $continuation, write: false);
            self::yield($loop);
            $loop->unwatch($reader, $continuation, write: false);
            fclose($reader);
            self::yield($loop);
        });

        Assert::same(self::streams($loop), [[], 0]);
    }

    public function streamClosedWhileWatchedIsForgottenOnceItsWaitsEnd(): void
    {
        [$reader] = self::pair();
        $loop = self::loop();

        $loop->run(static function () use ($loop, $reader) {
            $first = new ContinuationImpl();
            $loop->watch($reader, $first, write: false);
            self::yield($loop);
            fclose($reader);
            $second = new ContinuationImpl();
            $loop->watch($reader, $second, write: true);
            self::yield($loop);
            $loop->unwatch($reader, $first, write: false);
            $loop->unwatch($reader, $second, write: true);
            self::yield($loop);
        });

        Assert::same(self::streams($loop), [[], 0]);
    }

    public function closedStreamIsReadyAtOnce(): void
    {
        [$reader] = self::pair();
        fclose($reader);
        $loop = self::loop();

        $ready = $loop->run(static function () use ($loop, $reader) {
            $continuation = new ContinuationImpl();
            $loop->watch($reader, $continuation, write: false);

            return $continuation->park();
        });

        Assert::true($ready);
    }

    public function streamWithoutDescriptorIsReadyAtOnce(): void
    {
        $stream = fopen('php://memory', 'r+');
        \assert($stream !== false);
        $loop = self::loop();

        $ready = $loop->run(static function () use ($loop, $stream) {
            $continuation = new ContinuationImpl();
            $loop->watch($stream, $continuation, write: true);

            return $continuation->park();
        });

        Assert::true($ready);
    }

    public function signalIsHandledInsteadOfItsDefaultAction(): void
    {
        $loop = self::loop();
        $received = [];

        $loop->run(static function () use ($loop, &$received) {
            $continuation = new ContinuationImpl();
            $handler = static function (int $signal) use ($continuation, &$received) {
                $received[] = $signal;
                $continuation->resume();
            };
            $loop->onSignal(SIGUSR1, $handler);
            posix_kill(posix_getpid(), SIGUSR1);
            $continuation->park();
            $loop->offSignal(SIGUSR1, $handler);
        });

        Assert::same($received, [SIGUSR1]);
    }

    public function signalCutsWaitShortWithoutFailingIt(): void
    {
        $loop = new Loop();
        $received = [];

        $loop->run(static function () use ($loop, &$received) {
            $handler = static function (int $signal) use (&$received) {
                $received[] = $signal;
            };
            $loop->onSignal(SIGUSR1, $handler);
            $process = proc_open(
                command: ['sh', '-c', 'sleep 0.05; kill -USR1 ' . getmypid()],
                descriptor_spec: [],
                pipes: $pipes,
            );
            \assert($process !== false);
            $timer = new ContinuationImpl();
            $loop->delay(toNs(ms(300)), $timer);
            $timer->park();
            proc_close($process);
            $loop->offSignal(SIGUSR1, $handler);
        });

        Assert::same($received, [SIGUSR1]);
    }

    public function previousHandlerOfSignalComesBackWhenLastHandlerIsGone(): void
    {
        $previous = static function () {};
        pcntl_signal(SIGUSR2, $previous);
        $loop = self::loop();

        try {
            $loop->run(static function () use ($loop, $previous) {
                $first = static function () {};
                $second = static function () {};
                $loop->onSignal(SIGUSR2, $first);
                $loop->onSignal(SIGUSR2, $second);
                $loop->offSignal(SIGUSR2, $first);

                Assert::notSame(pcntl_signal_get_handler(SIGUSR2), $previous);

                $loop->offSignal(SIGUSR2, $second);

                Assert::same(pcntl_signal_get_handler(SIGUSR2), $previous);
            });
        } finally {
            pcntl_signal(SIGUSR2, SIG_DFL);
        }
    }

    public function signalHandlerKeepsLoopAlive(): void
    {
        $loop = self::loop();

        $signal = $loop->run(static function () use ($loop) {
            $continuation = new ContinuationImpl();
            $handler = static function (int $signal) use ($continuation) {
                $continuation->resume($signal);
            };
            $loop->onSignal(SIGUSR1, $handler);
            $loop->start(static function () use ($loop) {
                self::yield($loop);
                posix_kill(posix_getpid(), SIGUSR1);
            });

            try {
                return $continuation->park();
            } finally {
                $loop->offSignal(SIGUSR1, $handler);
            }
        });

        Assert::same($signal, SIGUSR1);
    }

    public function loopThatHasRunGoesAway(): void
    {
        $loop = self::loop();
        $loop->run(static function () use ($loop) {
            $loop->start(static fn() => self::yield($loop));
            $timer = new ContinuationImpl();
            $loop->delay(toNs(sec(1)), $timer);
            $timer->park();
        });
        $reference = \WeakReference::create($loop);
        unset($loop);
        gc_collect_cycles();

        Assert::null($reference->get());
    }

    public function loopGoesAwayWithFiberLeftSuspended(): void
    {
        $loop = self::loop();
        $loop->run(static function () use ($loop) {
            $loop->start(static fn() => new ContinuationImpl()->park());
            self::yield($loop);
        });
        $reference = \WeakReference::create($loop);
        unset($loop);
        gc_collect_cycles();

        Assert::null($reference->get());
    }

    private static function loop(): Loop
    {
        return new Loop(new VirtualClock(0));
    }

    /**
     * Yields the current fiber to the next tick.
     */
    private static function yield(Loop $loop): mixed
    {
        $continuation = new ContinuationImpl();
        $loop->defer($continuation);

        return $continuation->park();
    }

    /**
     * @return array{int, int, int} the pending, the referenced and the outdated timers
     */
    private static function timers(Loop $loop): array
    {
        /** @var array{int, int, int} */
        return (fn() => [$this->pendingTimers, $this->referencedTimers, $this->outdatedTimers])->call($loop);
    }

    /**
     * @return array{array<int, WatchedStream>, int} the watched streams and how many fibers wait for them
     */
    private static function streams(Loop $loop): array
    {
        /** @var array{array<int, WatchedStream>, int} */
        return (fn() => [$this->streams, $this->streamWaits])->call($loop);
    }

    /**
     * @return array{resource, resource}
     */
    private static function pair(): array
    {
        /** @var array{resource, resource} $pair */
        $pair = stream_socket_pair(domain: STREAM_PF_UNIX, type: STREAM_SOCK_STREAM, protocol: STREAM_IPPROTO_IP);
        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);

        return $pair;
    }
}
