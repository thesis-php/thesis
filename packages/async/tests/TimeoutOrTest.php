<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Test;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\never;
use function Thesis\Async\Tests\thrown;

#[Test]
final class TimeoutOrTest
{
    public function returnsResultWithinDeadline(): void
    {
        $result = timeoutOr(1_000, static function () {
            delay(1);

            return 'result';
        }, 'default');

        Assert::same($result, 'result');
        assertNoCallbacksLeft();
    }

    public function returnsDefaultWhenDeadlineExpires(): void
    {
        Assert::same(timeoutOr(1, never(...), 'default'), 'default');
        Assert::null(timeoutOr(1, never(...)));
        assertNoCallbacksLeft();
    }

    public function returnsDefaultForExpiredDeadline(): void
    {
        Assert::same(timeoutOr(-1, never(...), 'default'), 'default');
        assertNoCallbacksLeft();
    }

    public function rejectsNan(): void
    {
        $ran = false;

        $exception = thrown(static function () use (&$ran) {
            timeoutOr(NAN, static function () use (&$ran) {
                $ran = true;
            }, 'default');
        });

        Assert::instanceOf($exception, \ValueError::class);
        Assert::false($ran);
        assertNoCallbacksLeft();
    }

    public function keepsWhatBodyDidBeforeDeadline(): void
    {
        $done = [];

        timeoutOr(1, static function () use (&$done) {
            $done[] = 1;
            delay(2);
            $done[] = 2;
        });

        Assert::same($done, [1]);
    }

    public function doesNotSwallowTimedOutOfNestedTimeout(): void
    {
        $exception = thrown(static fn() => timeoutOr(10, static fn() => timeout(1, never(...)), 'default'));

        Assert::instanceOf($exception, TimedOut::class);
        assertNoCallbacksLeft();
    }

    public function nestedTimeoutOrDoesNotClaimOuterDeadline(): void
    {
        $inner = 'not reached';

        $outer = timeoutOr(1, static function () use (&$inner) {
            $inner = timeoutOr(10, never(...), 'inner');
        }, 'outer');

        Assert::same($outer, 'outer');
        Assert::same($inner, 'not reached');
    }

    public function outerCancellationOutranksDeadline(): void
    {
        $inner = null;

        $outer = thrown(static function () use (&$inner) {
            timeout(1, static function () use (&$inner) {
                try {
                    never();
                } finally {
                    $inner = thrown(static fn() => timeoutOr(-1, never(...), 'default'));
                }
            });
        });

        Assert::instanceOf($inner, Cancelled::class);
        Assert::instanceOf($outer, TimedOut::class);
        assertNoCallbacksLeft();
    }

    public function passesFailureThrough(): void
    {
        $exception = thrown(static fn() => timeoutOr(1_000, static fn() => throw new \LogicException('failure')));

        Assert::instanceOf($exception, \LogicException::class);
        assertNoCallbacksLeft();
    }

    public function nowIsTheClockOfDeadlines(): void
    {
        $at = now() + 1;

        $remaining = timeout($at - now(), remaining(...));

        Assert::same($remaining, 1.0);

        $start = now();
        delay(10);
        Assert::same(now() - $start, 10.0);
    }
}
