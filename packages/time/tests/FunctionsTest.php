<?php

declare(strict_types=1);

namespace Thesis\Time;

use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Test;
use Time\Duration;
use function Thesis\Time\Internal\divide;

#[Test]
final class FunctionsTest
{
    private const string OUT_OF_RANGE = 'Time\TimeException: The maximum representable range is 9_223_372_035 seconds (roughly 292 years)';

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function calls(): iterable
    {
        yield 'ns' => [static fn() => ns(1_500_000_001), '1.500000001'];
        yield 'negative ns' => [static fn() => ns(-1), '-0.000000001'];
        yield 'min int ns' => [static fn() => ns(PHP_INT_MIN), self::OUT_OF_RANGE];
        yield 'max int ns' => [static fn() => ns(PHP_INT_MAX), self::OUT_OF_RANGE];
        yield 'us' => [static fn() => us(1.5), '0.000001500'];
        yield 'ms' => [static fn() => ms(250), '0.250000000'];
        yield 'negative ms' => [static fn() => ms(-1500), '-1.500000000'];
        yield 'sec' => [static fn() => sec(90), '90.000000000'];
        yield 'float sec' => [static fn() => sec(1.1), '1.100000000'];
        yield 'sec rounds half away from zero' => [static fn() => sec(0.000_000_000_5), '0.000000001'];
        yield 'negative sec rounds half away from zero' => [static fn() => sec(-0.000_000_000_5), '-0.000000001'];
        yield 'max sec' => [static fn() => sec(9_223_372_035), '9223372035.000000000'];
        yield 'sec above max' => [static fn() => sec(9_223_372_036), self::OUT_OF_RANGE];
        yield 'negative sec below min' => [static fn() => sec(-9_223_372_036), self::OUT_OF_RANGE];
        yield 'max int sec' => [static fn() => sec(PHP_INT_MAX), self::OUT_OF_RANGE];
        yield 'min int sec' => [static fn() => sec(PHP_INT_MIN), self::OUT_OF_RANGE];
        yield 'float sec above max' => [static fn() => sec(1e10), self::OUT_OF_RANGE];
        yield 'float sec at 2^63 ns' => [static fn() => sec(9.223_372_036_854_775_808e9), self::OUT_OF_RANGE];
        yield 'float sec at -2^63 ns' => [static fn() => sec(-9.223_372_036_854_775_808e9), self::OUT_OF_RANGE];
        yield 'NAN sec' => [static fn() => sec(NAN), 'ValueError: A duration must be finite, NAN given'];
        yield 'INF sec' => [static fn() => sec(INF), 'ValueError: A duration must be finite, INF given'];
        yield 'minutes' => [static fn() => minutes(1.5), '90.000000000'];
        yield 'hours' => [static fn() => hours(0.25), '900.000000000'];
        yield 'negative hours' => [static fn() => hours(-2), '-7200.000000000'];
        yield 'duration' => [
            static fn() => duration(hours: 1, minutes: 2, sec: 3, ms: 4, us: 5, ns: 6),
            '3723.004005006',
        ];
        yield 'duration of mixed signs' => [static fn() => duration(minutes: 1, sec: -90), '-30.000000000'];
        yield 'empty duration' => [static fn() => duration(), '0.000000000'];
        yield 'toNs' => [static fn() => toNs(sec(1.5)), '1500000000'];
        yield 'toNs of max' => [
            static fn() => toNs(Duration::fromSeconds(9_223_372_035, 999_999_999)),
            '9223372035999999999',
        ];
        yield 'toNs of min' => [
            static fn() => toNs(Duration::fromSeconds(9_223_372_035, 999_999_999)->negate()),
            '-9223372035999999999',
        ];
        yield 'toUs' => [static fn() => toUs(ns(1999)), '1'];
        yield 'toMs' => [static fn() => toMs(us(1999)), '1'];
        yield 'toMs rounded up' => [static fn() => toMs(us(1001), \RoundingMode::PositiveInfinity), '2'];
        yield 'toSec' => [static fn() => toSec(ms(-1999)), '-1'];
        yield 'toSec rounded down' => [static fn() => toSec(ms(-1001), \RoundingMode::NegativeInfinity), '-2'];
        yield 'toMinutes' => [static fn() => toMinutes(sec(119)), '1'];
        yield 'toHours' => [static fn() => toHours(minutes(-119)), '-1'];
        yield 'toSecFloat' => [static fn() => toSecFloat(ms(1500)), '1.5'];
        yield 'negative toSecFloat' => [static fn() => toSecFloat(ns(-1)), '-1.0E-9'];
        yield 'isZero' => [static fn() => [isZero(ns(0)), isZero(ns(1)), isZero(ns(-1))], '[true, false, false]'];
        yield 'isNegative' => [
            static fn() => [isNegative(ns(0)), isNegative(ns(1)), isNegative(ns(-1))],
            '[false, false, true]',
        ];
        yield 'isPositive' => [
            static fn() => [isPositive(ns(0)), isPositive(ns(1)), isPositive(ns(-1))],
            '[false, true, false]',
        ];
        yield 'shortest' => [static fn() => shortest(sec(1), ms(-1), ns(5)), '-0.001000000'];
        yield 'shortest of one' => [static fn() => shortest(sec(1)), '1.000000000'];
        yield 'longest' => [static fn() => longest(sec(-1), ms(1), ns(5)), '0.001000000'];
        yield 'multiply' => [static fn() => multiply(ms(1500), 3), '4.500000000'];
        yield 'multiply by negative' => [static fn() => multiply(ms(1500), -2), '-3.000000000'];
        yield 'multiply negative by negative' => [static fn() => multiply(ms(-1500), -2), '3.000000000'];
        yield 'multiply by float' => [static fn() => multiply(sec(1), 1.5), '1.500000000'];
        yield 'multiply by float rounds' => [static fn() => multiply(ns(3), 0.5), '0.000000002'];
        yield 'multiply by negative float' => [static fn() => multiply(sec(2), -0.25), '-0.500000000'];
        yield 'multiply by min int' => [static fn() => multiply(ns(1), PHP_INT_MIN), self::OUT_OF_RANGE];
        yield 'multiply zero by min int' => [static fn() => multiply(ns(0), PHP_INT_MIN), '0.000000000'];
        yield 'multiply above max' => [static fn() => multiply(sec(9_223_372_035), 2), self::OUT_OF_RANGE];
    }

    /**
     * @param \Closure(): mixed $call
     */
    #[DataProvider('calls')]
    public function returns(\Closure $call, string $expected): void
    {
        Assert::same(outcome($call), $expected);
    }

    /**
     * @return iterable<string, array{Duration, string}>
     */
    public static function formats(): iterable
    {
        yield '0s' => [ns(0), '0s'];
        yield '15ns' => [ns(15), '15ns'];
        yield '1µs' => [us(1), '1µs'];
        yield '1.5µs' => [ns(1500), '1.5µs'];
        yield '2.5ms' => [us(2500), '2.5ms'];
        yield '999.999999ms' => [ns(999_999_999), '999.999999ms'];
        yield '1s' => [sec(1), '1s'];
        yield '1.5s' => [ms(1500), '1.5s'];
        yield '1.000000001s' => [ns(1_000_000_001), '1.000000001s'];
        yield '1m0s' => [minutes(1), '1m0s'];
        yield '1m30s' => [sec(90), '1m30s'];
        yield '1h0m0s' => [hours(1), '1h0m0s'];
        yield '1h2m3.5s' => [duration(hours: 1, minutes: 2, sec: 3.5), '1h2m3.5s'];
        yield '25h0m0s' => [hours(25), '25h0m0s'];
        yield '-1.5s' => [ms(-1500), '-1.5s'];
        yield '-15ns' => [ns(-15), '-15ns'];
        yield 'max' => [Duration::fromSeconds(9_223_372_035, 999_999_999), '2562047h47m15.999999999s'];
    }

    #[DataProvider('formats')]
    public function formatsLikeGo(Duration $duration, string $expected): void
    {
        Assert::same(format($duration), $expected);
    }

    public function dividesAsRoundOfFloats(): void
    {
        foreach (\RoundingMode::cases() as $rounding) {
            for ($dividend = -3500; $dividend <= 3500; $dividend += 250) {
                Assert::same(
                    actual: divide($dividend, 1000, $rounding),
                    expected: (int) round($dividend / 1000, mode: $rounding),
                    message: "{$dividend} / 1000, {$rounding->name}",
                );
            }
        }
    }

    public function dividesExtremesExactly(): void
    {
        Assert::same(divide(PHP_INT_MAX, 1, \RoundingMode::HalfEven), PHP_INT_MAX);
        Assert::same(divide(PHP_INT_MIN, 1, \RoundingMode::HalfEven), PHP_INT_MIN);
        Assert::same(divide(PHP_INT_MAX, 2, \RoundingMode::HalfAwayFromZero), intdiv(PHP_INT_MAX, 2) + 1);
        Assert::same(divide(PHP_INT_MIN, 2, \RoundingMode::AwayFromZero), intdiv(PHP_INT_MIN, 2));
        Assert::same(divide(PHP_INT_MAX, PHP_INT_MAX, \RoundingMode::TowardsZero), 1);
        Assert::same(divide(PHP_INT_MAX - 1, PHP_INT_MAX, \RoundingMode::HalfTowardsZero), 1);
        Assert::same(divide(1, PHP_INT_MAX, \RoundingMode::HalfAwayFromZero), 0);
    }
}
