<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\busy;
use function Thesis\Async\Tests\thrown;

#[Test]
final class TimeoutTest
{
    public function returnsResultWithinDeadline(): void
    {
        $result = timeout(1, static function () {
            delay(0.001);

            return 'result';
        });

        Assert::same($result, 'result');
        assertNoCallbacksLeft();
    }

    public function throwsTimedOutWhenDeadlineExpires(): void
    {
        $exception = thrown(static fn() => timeout(0.001, static fn() => delay(1)));

        Assert::instanceOf($exception, TimedOut::class);
        Assert::same($exception->seconds, 0.001);
        Assert::same($exception->getMessage(), 'Timed out after 0.001 seconds.');
        Assert::instanceOf($exception->getPrevious(), Cancelled::class);
        assertNoCallbacksLeft();
    }

    public function coversNestedGroups(): void
    {
        Expect::exception(TimedOut::class);

        timeout(0.001, static function () {
            group(static function (Group $group) {
                $group->spawn(static fn() => delay(1));
            });
        });
    }

    public function innerTimeoutDoesNotClaimOuterDeadline(): void
    {
        $inner = null;

        $outer = thrown(static function () use (&$inner) {
            timeout(0.001, static function () use (&$inner) {
                $inner = thrown(static fn() => timeout(10, static fn() => delay(1)));

                throw $inner;
            });
        });

        Assert::instanceOf($inner, Cancelled::class);
        Assert::instanceOf($outer, TimedOut::class);
        Assert::same($outer->seconds, 0.001);
    }

    public function outerCancellationOutranksExpiredInnerDeadline(): void
    {
        $inner = null;

        $outer = thrown(static function () use (&$inner) {
            timeout(0.001, static function () use (&$inner) {
                $inner = thrown(static function () {
                    timeout(0.002, static function () {
                        busy(0.005); // both deadlines expire before anyone looks
                        delay(1);
                    });
                });

                throw $inner;
            });
        });

        Assert::instanceOf($inner, Cancelled::class);
        Assert::instanceOf($outer, TimedOut::class);
        Assert::same($outer->seconds, 0.001);
    }

    public function passesForeignCancellationThrough(): void
    {
        $seen = null;

        $exception = thrown(static function () use (&$seen) {
            group(static function (Group $group) use (&$seen) {
                $group->spawn(static function () {
                    delay(0.001);

                    throw new \RuntimeException('task failed');
                });

                $seen = thrown(static fn() => timeout(10, static fn() => delay(1)));
            });
        });

        Assert::instanceOf($seen, Cancelled::class);
        Assert::instanceOf($exception, \RuntimeException::class);
    }

    public function passesFailureThrough(): void
    {
        Expect::exception(\LogicException::class)->withMessage('failure');

        timeout(1, static function () {
            throw new \LogicException('failure');
        });
    }

    public function failureCausedByDeadlineIsNotReplacedWithTimedOut(): void
    {
        Expect::exception(\RuntimeException::class)->withMessage('connection reset');

        timeout(0.001, static function () {
            try {
                delay(1);
            } catch (Cancelled) {
                throw new \RuntimeException('connection reset');
            }
        });
    }

    public function valueReturnedAfterDeadlineWins(): void
    {
        $result = timeout(0.001, static function () {
            try {
                delay(1);
            } catch (Cancelled) {
            }

            return 'value';
        });

        Assert::same($result, 'value');
    }

    public function restoresCurrentScope(): void
    {
        thrown(static fn() => timeout(0.001, static fn() => delay(1)));

        delay(0.001); // would throw if the expired scope stayed current

        group(static function (Group $group) {
            $group->spawn(static function () {
                thrown(static fn() => timeout(0.001, static fn() => delay(1)));

                delay(0.001);
            });
        });

        assertNoCallbacksLeft();
    }
}
