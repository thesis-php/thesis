<?php

declare(strict_types=1);

namespace Thesis\Async;

use Revolt\EventLoop;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\never;
use function Thesis\Async\Tests\thrown;
use function Thesis\Async\Tests\untilCancelled;
use function Thesis\Async\VirtualTime\advance;

#[Test]
final class TimeoutTest
{
    public function returnsResultWithinDeadline(): void
    {
        $result = timeout(1_000, static function () {
            delay(1);

            return 'result';
        });

        Assert::same($result, 'result');
        assertNoCallbacksLeft();
    }

    public function throwsTimedOutWhenDeadlineExpires(): void
    {
        $exception = thrown(static fn() => timeout(1, never(...)));

        Assert::instanceOf($exception, TimedOut::class);
        Assert::same($exception->seconds, 1.0);
        Assert::same($exception->getMessage(), 'Timed out after 1 seconds.');
        Assert::instanceOf($exception->getPrevious(), Cancelled::class);
        assertNoCallbacksLeft();
    }

    public function expiredDeadlineThrowsOnFirstWait(): void
    {
        foreach ([0.0, -1.0] as $seconds) {
            $callbacks = null;

            $exception = thrown(static function () use ($seconds, &$callbacks) {
                timeout($seconds, static function () use (&$callbacks) {
                    // Inside the body, not after it: a timer cancelled in finally would pass the check after.
                    $callbacks = EventLoop::getIdentifiers();
                    never();
                });
            });

            Assert::same($callbacks, []);
            Assert::instanceOf($exception, TimedOut::class);
            Assert::same($exception->seconds, $seconds);
            assertNoCallbacksLeft();
        }
    }

    public function rejectsNan(): void
    {
        $ran = false;

        $exception = thrown(static function () use (&$ran) {
            timeout(NAN, static function () use (&$ran) {
                $ran = true;
            });
        });

        Assert::instanceOf($exception, \ValueError::class);
        Assert::false($ran);
        assertNoCallbacksLeft();
    }

    public function expiredDeadlineReturnsResultOfBodyThatDoesNotWait(): void
    {
        Assert::same(timeout(-1, static fn() => remaining()), 0.0);
        assertNoCallbacksLeft();
    }

    public function expiredDeadlineDoesNotClaimOuterCancellation(): void
    {
        $inner = null;
        $outer = thrown(static function () use (&$inner) {
            timeout(1, static function () use (&$inner) {
                try {
                    never();
                } finally {
                    $inner = thrown(static fn() => timeout(-1, never(...)));
                }
            });
        });

        Assert::instanceOf($inner, Cancelled::class);
        Assert::instanceOf($outer, TimedOut::class);
        assertNoCallbacksLeft();
    }

    public function coversNestedGroups(): void
    {
        Expect::exception(TimedOut::class);

        timeout(1, static function () {
            group(static function (Group $group) {
                $group->spawn(never(...));
            });
        });
    }

    public function innerTimeoutDoesNotClaimOuterDeadline(): void
    {
        $inner = null;

        $outer = thrown(static function () use (&$inner) {
            timeout(1, static function () use (&$inner) {
                $inner = thrown(static fn() => timeout(10, never(...)));

                throw $inner;
            });
        });

        Assert::instanceOf($inner, Cancelled::class);
        Assert::instanceOf($outer, TimedOut::class);
        Assert::same($outer->seconds, 1.0);
    }

    public function outerCancellationOutranksExpiredInnerDeadline(): void
    {
        $inner = null;

        $outer = thrown(static function () use (&$inner) {
            timeout(1, static function () use (&$inner) {
                $inner = thrown(static function () {
                    timeout(2, static function () {
                        advance(5); // both deadlines expire before anyone looks
                        never();
                    });
                });

                throw $inner;
            });
        });

        Assert::instanceOf($inner, Cancelled::class);
        Assert::instanceOf($outer, TimedOut::class);
        Assert::same($outer->seconds, 1.0);
    }

    public function passesForeignCancellationThrough(): void
    {
        $seen = null;

        $exception = thrown(static function () use (&$seen) {
            group(static function (Group $group) use (&$seen) {
                $group->spawn(static function () {
                    delay(1);

                    throw new \RuntimeException('task failed');
                });

                $seen = thrown(static fn() => timeout(10, never(...)));
            });
        });

        Assert::instanceOf($seen, Cancelled::class);
        Assert::instanceOf($exception, \RuntimeException::class);
    }

    public function passesFailureThrough(): void
    {
        Expect::exception(\LogicException::class)->withMessage('failure');

        timeout(1_000, static function () {
            throw new \LogicException('failure');
        });
    }

    public function failureCausedByDeadlineIsNotReplacedWithTimedOut(): void
    {
        Expect::exception(\RuntimeException::class)->withMessage('connection reset');

        timeout(1, static function () {
            try {
                never();
            } catch (Cancelled) {
                throw new \RuntimeException('connection reset');
            }
        });
    }

    public function valueReturnedAfterDeadlineWins(): void
    {
        $result = timeout(1, static function () {
            untilCancelled();

            return 'value';
        });

        Assert::same($result, 'value');
    }

    public function restoresCurrentScope(): void
    {
        thrown(static fn() => timeout(1, never(...)));

        delay(1); // would throw if the expired scope stayed current

        group(static function (Group $group) {
            $group->spawn(static function () {
                thrown(static fn() => timeout(1, never(...)));

                delay(1);
            });
        });

        assertNoCallbacksLeft();
    }
}
