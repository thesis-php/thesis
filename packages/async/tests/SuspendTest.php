<?php

declare(strict_types=1);

namespace Thesis\Async;

use Revolt\EventLoop;
use Revolt\EventLoop\UncaughtThrowable;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\never;
use function Thesis\Async\Tests\thrown;

#[Test]
final class SuspendTest
{
    public function returnsValueResumedLater(): void
    {
        $value = suspend(static function (Continuation $continuation) {
            EventLoop::delay(1, static function () use ($continuation) {
                $continuation->resume('later');
            });
        });

        Assert::same($value, 'later');
    }

    public function returnsValueResumedBeforeParking(): void
    {
        $value = suspend(static function (Continuation $continuation) {
            $continuation->resume('immediately');
        });

        Assert::same($value, 'immediately');
    }

    public function returnsNullByDefault(): void
    {
        $value = suspend(static function (Continuation $continuation) {
            $continuation->resume();
        });

        Assert::null($value);
    }

    public function throwsErrorPassedToThrow(): void
    {
        Expect::exception(\RuntimeException::class)->withMessage('source failed');

        suspend(static function (Continuation $continuation) {
            EventLoop::defer(static function () use ($continuation) {
                $continuation->throw(new \RuntimeException('source failed'));
            });
        });
    }

    public function throwsErrorPassedToThrowBeforeParking(): void
    {
        Expect::exception(\RuntimeException::class)->withMessage('source failed');

        suspend(static function (Continuation $continuation) {
            $continuation->throw(new \RuntimeException('source failed'));
        });
    }

    public function onlyClosureReturnedByRegisterIsCalled(): void
    {
        // A Revolt callback id is a string, and a string may be callable: "dl", "die".
        // gc_disable() stands for them: harmless, and its effect is visible.
        // @phpstan-ignore argument.type (the type rules it out, code without types does not)
        suspend(static function (Continuation $continuation) {
            $continuation->resume();

            return 'gc_disable';
        });

        $enabled = gc_enabled();
        gc_enable();
        Assert::true($enabled);
    }

    public function continuationIsOneShot(): void
    {
        $results = [];

        $value = suspend(static function (Continuation $continuation) use (&$results) {
            $results[] = $continuation->resume('first');
            $results[] = $continuation->resume('second');
            $results[] = $continuation->throw(new \RuntimeException());
        });

        Assert::same($value, 'first');
        Assert::same($results, [true, false, false]);
    }

    public function throwIsOneShot(): void
    {
        $results = [];

        $exception = thrown(static function () use (&$results) {
            suspend(static function (Continuation $continuation) use (&$results) {
                $results[] = $continuation->throw(new \RuntimeException('first'));
                $results[] = $continuation->throw(new \LogicException('second'));
                $results[] = $continuation->resume('value');
            });
        });

        Assert::instanceOf($exception, \RuntimeException::class);
        Assert::same($exception->getMessage(), 'first');
        Assert::same($results, [true, false, false]);
    }

    public function lateResumeOfFinishedWaitIsIgnored(): void
    {
        $first = null;

        suspend(static function (Continuation $continuation) use (&$first) {
            $first = $continuation;
            $continuation->resume();
        });
        $second = suspend(static function (Continuation $continuation) use (&$first) {
            /** @var Continuation<mixed> $first */
            Assert::false($first->resume('stale'));
            $continuation->resume('fresh');
        });

        Assert::same($second, 'fresh');
    }

    public function callsUnregisterAfterResumption(): void
    {
        $calls = 0;

        suspend(static function (Continuation $continuation) use (&$calls) {
            EventLoop::defer(static function () use ($continuation) {
                $continuation->resume();
            });

            return static function () use (&$calls) {
                ++$calls;
            };
        });

        Assert::same($calls, 1);
    }

    public function callsUnregisterAfterError(): void
    {
        $calls = 0;

        thrown(static function () use (&$calls) {
            suspend(static function (Continuation $continuation) use (&$calls) {
                $continuation->throw(new \RuntimeException());

                return static function () use (&$calls) {
                    ++$calls;
                };
            });
        });

        Assert::same($calls, 1);
    }

    public function callsUnregisterAfterCancellation(): void
    {
        $calls = 0;

        thrown(static function () use (&$calls) {
            timeout(1, static function () use (&$calls) {
                suspend(static function (Continuation $continuation) use (&$calls) {
                    return static function () use (&$calls) {
                        ++$calls;
                    };
                });
            });
        });

        Assert::same($calls, 1);
    }

    public function callsUnregisterWhenEventLoopThrowsInMain(): void
    {
        $calls = 0;

        $exception = thrown(static function () use (&$calls) {
            suspend(static function (Continuation $_) use (&$calls) {
                // In {main}, the wait runs the event loop, so this throws out of the wait.
                EventLoop::defer(static function () {
                    throw new \RuntimeException('foreign');
                });

                return static function () use (&$calls) {
                    ++$calls;
                };
            });
        });

        Assert::instanceOf($exception, UncaughtThrowable::class);
        Assert::same($calls, 1);
        assertNoCallbacksLeft();
    }

    public function cancellationInterruptsWait(): void
    {
        $continuation = null;

        $exception = thrown(static function () use (&$continuation) {
            timeout(1, static function () use (&$continuation) {
                suspend(static function (Continuation $c) use (&$continuation) {
                    $continuation = $c;
                });
            });
        });

        Assert::instanceOf($exception, TimedOut::class);
        Assert::instanceOf($exception->getPrevious(), Cancelled::class);
        /** @var Continuation<mixed> $continuation */
        Assert::false($continuation->resume('too late'));
    }

    public function doesNotRegisterInCancelledScope(): void
    {
        $registered = false;

        $exception = thrown(static function () use (&$registered) {
            timeout(1, static function () use (&$registered) {
                try {
                    never();
                } catch (Cancelled) {
                }

                suspend(static function (Continuation $continuation) use (&$registered) {
                    $registered = true;
                });
            });
        });

        Assert::instanceOf($exception, TimedOut::class);
        Assert::false($registered);
    }

    public function eventBeforeCancellationWins(): void
    {
        $received = [];

        group(static function (Group $group) use (&$received) {
            $continuation = null;
            $consumer = $group->spawn(static function () use (&$continuation, &$received) {
                $received[] = suspend(static function (Continuation $c) use (&$continuation) {
                    $continuation = $c;
                });
                $received[] = 'returned';
                suspend(static function (Continuation $continuation) {});
            });
            delay(1);

            /** @var Continuation<mixed> $continuation */
            $continuation->resume('message');
            $consumer->cancel();
        });

        Assert::same($received, ['message', 'returned']);
    }

    public function cancelledTracePointsAtInterruptedWait(): void
    {
        $exception = thrown(static function () {
            timeout(1, static function () {
                suspend(static function (Continuation $continuation) {});
            });
        });

        $cancelled = $exception->getPrevious();
        Assert::instanceOf($cancelled, Cancelled::class);
        Assert::same($cancelled->getTrace()[1]['function'] ?? null, 'Thesis\Async\suspend');
        Assert::same($cancelled->getMessage(), 'The scope is cancelled.');
    }

    public function leavesNoCallbacks(): void
    {
        thrown(static function () {
            timeout(1, static function () {
                suspend(static function (Continuation $continuation) {
                    $id = EventLoop::delay(10_000, static function () use ($continuation) {
                        $continuation->resume();
                    });

                    return static function () use ($id) {
                        EventLoop::cancel($id);
                    };
                });
            });
        });

        assertNoCallbacksLeft();
    }

    public function throwOfCancelledIsRejected(): void
    {
        $cancelled = thrown(static fn() => timeout(1, never(...)))->getPrevious();
        Assert::instanceOf($cancelled, Cancelled::class);
        $rejection = null;

        $value = suspend(static function (Continuation $continuation) use ($cancelled, &$rejection) {
            $rejection = thrown(static fn() => $continuation->throw($cancelled));
            $continuation->resume('still pending');
        });

        Assert::instanceOf($rejection, \ValueError::class);
        Assert::same(
            $rejection->getMessage(),
            'A continuation cannot be thrown Cancelled: the waiting scope is not cancelled. Throw a regular exception.',
        );
        Assert::same($value, 'still pending');
    }

    public function cancellationDuringRegisterInterruptsWait(): void
    {
        $registered = false;

        $awaited = group(static function (Group $group) use (&$registered) {
            $task = $group->spawn(static function () use ($group, &$registered) {
                suspend(static function (Continuation $continuation) use ($group, &$registered) {
                    $registered = true;
                    $group->cancelTasks(); // the scope of this very wait
                });
            });

            return thrown(static fn() => $task->await());
        });

        Assert::true($registered);
        Assert::instanceOf($awaited, TaskCancelled::class);
    }
}
