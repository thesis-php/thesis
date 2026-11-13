<?php

declare(strict_types=1);

namespace Thesis\Async;

use Revolt\EventLoop;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\thrown;

#[Test]
final class SuspendTest
{
    public function returnsValueResumedLater(): void
    {
        $value = suspend(static function (Continuation $continuation) {
            EventLoop::delay(0.001, static function () use ($continuation) {
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
            timeout(0.001, static function () use (&$calls) {
                suspend(static function (Continuation $continuation) use (&$calls) {
                    return static function () use (&$calls) {
                        ++$calls;
                    };
                });
            });
        });

        Assert::same($calls, 1);
    }

    public function cancellationInterruptsWait(): void
    {
        $continuation = null;

        $exception = thrown(static function () use (&$continuation) {
            timeout(0.001, static function () use (&$continuation) {
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
            timeout(0.001, static function () use (&$registered) {
                try {
                    delay(1);
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
            delay(0.001);

            /** @var Continuation<mixed> $continuation */
            $continuation->resume('message');
            $consumer->cancel();
        });

        Assert::same($received, ['message', 'returned']);
    }

    public function cancelledTracePointsAtInterruptedWait(): void
    {
        $exception = thrown(static function () {
            timeout(0.001, static function () {
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
            timeout(0.001, static function () {
                suspend(static function (Continuation $continuation) {
                    $id = EventLoop::delay(10, static function () use ($continuation) {
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
}
