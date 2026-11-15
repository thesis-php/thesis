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
        $result = timeoutOr(seconds: 1000, body: static function () {
            delay(1);

            return 'result';
        }, default: 'default');

        Assert::same($result, 'result');
        assertNoCallbacksLeft();
    }

    public function returnsDefaultWhenDeadlineExpires(): void
    {
        Assert::same(timeoutOr(1, never(...), default: 'default'), 'default');
        Assert::null(timeoutOr(1, never(...)));
        assertNoCallbacksLeft();
    }

    public function sentinelDefaultTellsExpiryFromNullResult(): void
    {
        $expired = new \stdClass();

        Assert::null(timeoutOr(1, static fn() => null, default: $expired));
        Assert::same(timeoutOr(1, never(...), default: $expired), $expired);
    }

    public function returnsDefaultForExpiredDeadline(): void
    {
        Assert::same(timeoutOr(-1, never(...), default: 'default'), 'default');
        assertNoCallbacksLeft();
    }

    public function rejectsNan(): void
    {
        $ran = false;

        $exception = thrown(static function () use (&$ran) {
            timeoutOr(NAN, static function () use (&$ran) {
                $ran = true;
            });
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
        $exception = thrown(static fn() => timeoutOr(10, static fn() => timeout(1, never(...))));

        Assert::instanceOf($exception, TimedOut::class);
        assertNoCallbacksLeft();
    }

    public function nestedTimeoutOrDoesNotClaimOuterDeadline(): void
    {
        $inner = 'not reached';

        $outer = timeoutOr(seconds: 1, body: static function () use (&$inner) {
            $inner = timeoutOr(10, never(...), default: 'inner');
        }, default: 'outer');

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
                    $inner = thrown(static fn() => timeoutOr(-1, never(...)));
                }
            });
        });

        Assert::instanceOf($inner, Cancelled::class);
        Assert::instanceOf($outer, TimedOut::class);
        assertNoCallbacksLeft();
    }

    public function passesFailureThrough(): void
    {
        $exception = thrown(static fn() => timeoutOr(1000, static fn() => throw new \LogicException('failure')));

        Assert::instanceOf($exception, \LogicException::class);
        assertNoCallbacksLeft();
    }
}
