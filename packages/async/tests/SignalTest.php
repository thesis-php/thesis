<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Internal\SignalTrapImpl;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\never;
use function Thesis\Async\Tests\thrown;
use function Thesis\Time\sec;

#[Test]
final class SignalTest
{
    public function sharedSignalHandlerRunsOnceUntilItsLastSubscriptionEnds(): void
    {
        $calls = [];
        $handler = static function (int $signal) use (&$calls) {
            $calls[] = $signal;
        };
        $previous = pcntl_signal_get_handler(SIGUSR1);
        $first = onSignal(SIGUSR1, $handler);
        $second = onSignal(SIGUSR1, $handler);

        try {
            posix_kill(posix_getpid(), SIGUSR1);
            delay(sec(1));
            Assert::same($calls, [SIGUSR1]);

            $first();
            $first();
            posix_kill(posix_getpid(), SIGUSR1);
            delay(sec(1));
            Assert::same($calls, [SIGUSR1, SIGUSR1]);
        } finally {
            $first();
            $second();
        }

        Assert::same(pcntl_signal_get_handler(SIGUSR1), $previous);
        assertNoCallbacksLeft();
    }

    public function innerLoopReleasesItsOwnSubscriptionToTheSameHandler(): void
    {
        $calls = 0;
        $handler = static function () use (&$calls) {
            ++$calls;
        };
        $outer = onSignal(SIGUSR1, $handler);

        try {
            virtualTime(static function () use ($handler, $outer) {
                $inner = onSignal(SIGUSR1, $handler);

                try {
                    Assert::instanceOf(thrown($outer), \LogicException::class);
                    posix_kill(posix_getpid(), SIGUSR1);
                    delay(sec(1));
                } finally {
                    $inner();
                }
            });

            Assert::same($calls, 1);
            posix_kill(posix_getpid(), SIGUSR1);
            delay(sec(1));
            Assert::same($calls, 2);
        } finally {
            $outer();
        }

        assertNoCallbacksLeft();
    }

    public function awaitShutdownSignalReturnsInterruptOrTerminate(): void
    {
        foreach ([SIGINT, SIGTERM] as $expected) {
            $signal = group(static function (Group $group) use ($expected) {
                $group->spawn(static fn() => self::kill($expected));

                return timeout(sec(10), awaitShutdownSignal(...));
            });

            Assert::same($signal, $expected);
            assertNoCallbacksLeft();
        }
    }

    public function awaitSignalReturnsSignalReceived(): void
    {
        $signal = group(static function (Group $group) {
            $group->spawn(static fn() => self::kill(SIGUSR1));

            return awaitSignal(SIGUSR1);
        });

        Assert::same($signal, SIGUSR1);
        assertNoCallbacksLeft();
    }

    public function awaitSignalReturnsWhichOfSignalsWasReceived(): void
    {
        $signal = group(static function (Group $group) {
            $group->spawn(static fn() => self::kill(SIGUSR2));

            return awaitSignal(SIGUSR1, SIGUSR2);
        });

        Assert::same($signal, SIGUSR2);
        assertNoCallbacksLeft();
    }

    public function awaitSignalWakesEveryWaiter(): void
    {
        $signals = all([
            static fn() => awaitSignal(SIGUSR1),
            static fn() => awaitSignal(SIGUSR2, SIGUSR1),
            static fn() => self::kill(SIGUSR1),
        ]);

        Assert::same($signals, [SIGUSR1, SIGUSR1, null]);
        assertNoCallbacksLeft();
    }

    public function awaitSignalIsCancellable(): void
    {
        Expect::exception(TimedOut::class);

        try {
            timeout(sec(1), static fn() => awaitSignal(SIGUSR1));
        } finally {
            assertNoCallbacksLeft();
        }
    }

    public function awaitSignalWaitsAgainForNextSignal(): void
    {
        $received = [];

        group(static function (Group $group) use (&$received) {
            $group->spawn(static function () {
                self::kill(SIGUSR1);
                self::kill(SIGUSR2);
            });

            $received[] = awaitSignal(SIGUSR1);
            $received[] = awaitSignal(SIGUSR2);
        });

        Assert::same($received, [SIGUSR1, SIGUSR2]);
        assertNoCallbacksLeft();
    }

    public function awaitSignalShutsDownGroup(): void
    {
        $events = [];

        group(static function (Group $group) use (&$events) {
            $group->daemon(static function () use (&$events) {
                try {
                    never(); // a server that never stops by itself
                } finally {
                    $events[] = 'server stopped';
                }
            });
            $group->spawn(static fn() => self::kill(SIGUSR1));

            awaitSignal(SIGUSR1);
            $events[] = 'signal received';
        });

        Assert::same($events, ['signal received', 'server stopped']);
        assertNoCallbacksLeft();
    }

    public function trapSignalsCatchesEverySignal(): void
    {
        $received = trapSignals([SIGUSR1, SIGUSR2], static function (SignalTrap $trap) {
            self::kill(SIGUSR1);
            self::kill(SIGUSR2);

            return [$trap->receive(), $trap->receive()];
        });

        Assert::same($received, [SIGUSR1, SIGUSR2]);
        assertNoCallbacksLeft();
    }

    public function trapSignalsCatchesSignalSentAtOnce(): void
    {
        $received = trapSignals([SIGUSR1], static function (SignalTrap $trap) {
            posix_kill(posix_getpid(), SIGUSR1);

            return $trap->receive();
        });

        Assert::same($received, SIGUSR1);
        assertNoCallbacksLeft();
    }

    public function trapSignalsInCancelledScopeDoesNotRunCallable(): void
    {
        $called = false;

        // A deadline of zero cancels the scope at once, and the body runs in it.
        $exception = thrown(static function () use (&$called) {
            timeout(sec(0), static function () use (&$called) {
                trapSignals([SIGUSR1], static function () use (&$called) {
                    $called = true;
                });
            });
        });

        Assert::instanceOf($exception, TimedOut::class);
        Assert::false($called);
        assertNoCallbacksLeft();
    }

    public function trapSignalsMergesSignalThatComesAgain(): void
    {
        $nothingMore = null;

        $received = trapSignals([SIGUSR1], static function (SignalTrap $trap) use (&$nothingMore) {
            self::kill(SIGUSR1); // nobody receives yet: pending
            self::kill(SIGUSR1); // merged with the pending one, and the process survives
            delay(sec(1)); // the handler runs at the next tick
            $received = $trap->receive();
            $nothingMore = thrown(static fn() => timeout(sec(5), $trap->receive(...)));

            return $received;
        });

        Assert::same($received, SIGUSR1);
        Assert::instanceOf($nothingMore, TimedOut::class);
        assertNoCallbacksLeft();
    }

    public function trapSignalsDoesNotLoseSignalBehindRepeatedOne(): void
    {
        $received = trapSignals([SIGUSR1, SIGUSR2], static function (SignalTrap $trap) {
            self::kill(SIGUSR1);
            self::kill(SIGUSR1);
            self::kill(SIGUSR2);

            return [$trap->receive(), $trap->receive()];
        });

        Assert::same($received, [SIGUSR1, SIGUSR2]);
        assertNoCallbacksLeft();
    }

    public function trapSignalsKeepsSignalThatComesDuringReload(): void
    {
        $reloads = 0;

        group(static function (Group $group) use (&$reloads) {
            $group->daemon(static function () use (&$reloads) {
                trapSignals([SIGUSR1], static function (SignalTrap $trap) use (&$reloads) {
                    foreach ($trap as $_) {
                        delay(sec(5)); // the next signal comes during this reload
                        ++$reloads;
                    }
                });
            });

            self::kill(SIGUSR1);
            self::kill(SIGUSR1);
            delay(sec(20));
        });

        Assert::same($reloads, 2);
        assertNoCallbacksLeft();
    }

    public function trapSignalsGivesNothingToCancelledReceiver(): void
    {
        $received = trapSignals([SIGUSR1], static fn(SignalTrap $trap): int => group(static function (
            Group $group,
        ) use ($trap) {
            $cancelled = $group->spawn(static fn() => $trap->receive());
            $other = $group->spawn(static fn() => $trap->receive());
            $group->spawn(static function () use ($cancelled) {
                delay(sec(1));
                $cancelled->cancel();
                posix_kill(posix_getpid(), SIGUSR1);
            });

            return $other->await();
        }));

        Assert::same($received, SIGUSR1);
        assertNoCallbacksLeft();
    }

    public function trapSignalsHandlesRepeatedNumberOnce(): void
    {
        $nothingMore = null;

        $received = trapSignals([SIGUSR1, SIGUSR1], static function (SignalTrap $trap) use (&$nothingMore) {
            self::kill(SIGUSR1);
            $received = $trap->receive();
            $nothingMore = thrown(static fn() => timeout(sec(5), $trap->receive(...)));

            return $received;
        });

        Assert::same($received, SIGUSR1);
        Assert::instanceOf($nothingMore, TimedOut::class);
        assertNoCallbacksLeft();
    }

    public function trapSignalsHandsSignalOverPastReceiverWhoseWaitIsOver(): void
    {
        $received = trapSignals([SIGUSR1], static fn(SignalTrap $trap): int => group(static function (
            Group $group,
        ) use ($trap) {
            $cancelled = $group->spawn(static fn() => $trap->receive());
            $other = $group->spawn(static fn() => $trap->receive());
            delay(sec(1));

            // The loop runs the cancelled fiber, which leaves the queue, before the next signal callback:
            // only a signal caught right after the cancellation, in the same tick, meets a receiver that refuses it.
            $cancelled->cancel();
            \assert($trap instanceof SignalTrapImpl);
            (fn() => $this->catch(SIGUSR1))->call($trap);

            Assert::instanceOf(thrown($cancelled->await(...)), TaskCancelled::class);

            return timeout(sec(1), $other->await(...));
        }));

        Assert::same($received, SIGUSR1);
        assertNoCallbacksLeft();
    }

    public function trapIsReleasedAfterward(): void
    {
        $trap = trapSignals([SIGUSR1], static fn(SignalTrap $trap) => $trap);

        Assert::same(iterator_to_array($trap), []);
        Expect::exception(\LogicException::class);
        $trap->receive();
    }

    public function releaseWakesWaitingReceiver(): void
    {
        $events = [];

        group(static function (Group $group) use (&$events) {
            trapSignals([SIGUSR1], static function (SignalTrap $trap) use ($group, &$events) {
                $group->spawn(static function () use ($trap, &$events) {
                    foreach ($trap as $_) {
                        $events[] = 'signal';
                    }

                    $events[] = 'trap released';
                });
                delay(sec(1)); // the receiver waits
            });
        });

        Assert::same($events, ['trap released']);
        assertNoCallbacksLeft();
    }

    public function signalTakenByWaitingReceiverIsNotPending(): void
    {
        $nothingMore = null;

        trapSignals([SIGUSR1], static function (SignalTrap $trap) use (&$nothingMore) {
            group(static function (Group $group) use ($trap) {
                $group->spawn(static fn() => self::kill(SIGUSR1));
                $trap->receive();
            });

            $nothingMore = thrown(static fn() => timeout(sec(5), $trap->receive(...)));
        });

        Assert::instanceOf($nothingMore, TimedOut::class);
    }

    public function trapHoldsNoReceiversAfterWaitsAreOver(): void
    {
        trapSignals([SIGUSR1], static function (SignalTrap $trap) {
            try {
                timeout(sec(1), $trap->receive(...));
            } catch (TimedOut) {
            }

            \assert($trap instanceof SignalTrapImpl);
            Assert::same((fn() => $this->receivers->items)->call($trap), []);
        });
    }

    public function trapSignalsRemovesHandlersOnFailure(): void
    {
        Expect::exception(\LogicException::class)->withMessage('failure');

        try {
            trapSignals([SIGUSR1], static function () {
                throw new \LogicException('failure');
            });
        } finally {
            assertNoCallbacksLeft();
        }
    }

    /**
     * Sent after the waiters have had a tick of the loop to trap the signal:
     * a signal sent before gets the default action and kills the test run.
     *
     * @param positive-int $signal
     */
    private static function kill(int $signal): void
    {
        delay(sec(1));
        posix_kill(posix_getpid(), $signal);
    }
}
