<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Test;
use function Thesis\Async\Tests\assertNoCallbacksLeft;
use function Thesis\Async\Tests\thrown;

#[Test]
final class TimeoutOrTest
{
    public function returnsResultWithinDeadline(): void
    {
        $result = timeoutOr(1, static function () {
            delay(0.001);

            return 'result';
        }, 'default');

        Assert::same($result, 'result');
        assertNoCallbacksLeft();
    }

    public function returnsDefaultWhenDeadlineExpires(): void
    {
        Assert::same(timeoutOr(0.001, static fn() => delay(1), 'default'), 'default');
        Assert::null(timeoutOr(0.001, static fn() => delay(1)));
        assertNoCallbacksLeft();
    }

    public function returnsDefaultForExpiredDeadline(): void
    {
        Assert::same(timeoutOr(-1, static fn() => delay(1), 'default'), 'default');
        assertNoCallbacksLeft();
    }

    public function keepsWhatBodyDidBeforeDeadline(): void
    {
        $done = [];

        timeoutOr(0.001, static function () use (&$done) {
            $done[] = 1;
            delay(1);
            $done[] = 2;
        });

        Assert::same($done, [1]);
    }

    public function doesNotSwallowTimedOutOfNestedTimeout(): void
    {
        $exception = thrown(static fn() => timeoutOr(1, static fn() => timeout(0.001, static fn() => delay(1)), 'default'));

        Assert::instanceOf($exception, TimedOut::class);
        assertNoCallbacksLeft();
    }

    public function nestedTimeoutOrDoesNotClaimOuterDeadline(): void
    {
        $inner = 'not reached';

        $outer = timeoutOr(0.001, static function () use (&$inner) {
            $inner = timeoutOr(1, static fn() => delay(1), 'inner');
        }, 'outer');

        Assert::same($outer, 'outer');
        Assert::same($inner, 'not reached');
    }

    public function outerCancellationOutranksDeadline(): void
    {
        $inner = null;

        $outer = thrown(static function () use (&$inner) {
            timeout(0.001, static function () use (&$inner) {
                try {
                    delay(1);
                } finally {
                    $inner = thrown(static fn() => timeoutOr(-1, static fn() => delay(1), 'default'));
                }
            });
        });

        Assert::instanceOf($inner, Cancelled::class);
        Assert::instanceOf($outer, TimedOut::class);
        assertNoCallbacksLeft();
    }

    public function passesFailureThrough(): void
    {
        $exception = thrown(static fn() => timeoutOr(1, static fn() => throw new \LogicException('failure')));

        Assert::instanceOf($exception, \LogicException::class);
        assertNoCallbacksLeft();
    }

    public function nowIsTheClockOfDeadlines(): void
    {
        $at = now() + 1;

        $remaining = timeout($at - now(), remaining(...));

        Assert::true($remaining !== null && $remaining <= 1 && $remaining > 0.9);

        $start = now();
        delay(0.01);
        Assert::true(now() - $start >= 0.01);
    }
}
