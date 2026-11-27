<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Test;
use Time\Duration;
use Time\TimeException;
use function Thesis\Async\Tests\thrown;
use function Thesis\Time\ms;
use function Thesis\Time\sec;
use const Thesis\Time\ZERO;

#[Test]
final class InstantTest
{
    public function elapsedMeasuresWaitsAndBusyWork(): void
    {
        $elapsed = Instant::elapsed(static function () {
            delay(sec(2));
            burnCpu(ms(500));
        });

        Assert::equals($elapsed, ms(2500));
    }

    public function elapsedLetsExceptionThrough(): void
    {
        $exception = new \RuntimeException();

        Assert::same(thrown(static fn() => Instant::elapsed(static fn() => throw $exception)), $exception);
    }

    public function sinceIsTimeBetweenInstants(): void
    {
        $start = Instant::now();
        delay(sec(3));
        $end = Instant::now();

        Assert::equals([$end->since($start), $start->since($end)], [sec(3), sec(-3)]);
    }

    public function addMovesInstantForward(): void
    {
        $deadline = Instant::now()->add(sec(5));
        delay(sec(1));

        Assert::equals($deadline->since(Instant::now()), sec(4));
    }

    public function instantsCompareWithOperators(): void
    {
        $now = Instant::now();
        $later = $now->add(ms(1));

        Assert::true($now < $later);
        Assert::equals($now, $now->add(ZERO));
    }

    public function addOutOfRangeOfClockThrows(): void
    {
        $longest = Duration::fromSeconds(9_223_372_035, 999_999_999);

        $exception = thrown(static fn() => Instant::now()->add($longest));

        Assert::instanceOf($exception, TimeException::class);
    }

    public function sinceOutOfRangeOfDurationThrows(): void
    {
        // Each is within the range of the clock, the difference of the two is beyond int.
        $future = Instant::now()->add(sec(9_000_000_000));
        $past = Instant::now()->add(sec(-9_000_000_000));

        $exception = thrown(static fn() => $future->since($past));

        Assert::instanceOf($exception, TimeException::class);
    }
}
