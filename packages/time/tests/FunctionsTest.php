<?php

declare(strict_types=1);

namespace Thesis\Time;

use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Data\DataProvider;
use Testo\Test;
use Time\Duration;

#[Test]
final class FunctionsTest
{
    private const OUT_OF_RANGE = \PHP_INT_SIZE === 4
        ? 'Time\TimeException: The maximum representable range is 2_147_483_647 seconds (roughly 68 years)'
        : 'Time\TimeException: The maximum representable range is 9_223_372_035 seconds (roughly 292 years)';
    private const NOT_INT = 'Time\TimeException: The duration in this unit does not fit in int';

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function calls(): iterable
    {
        yield 'ns' => [static fn() => ns(1_500_000_001), '1.500000001'];
        yield 'negative ns' => [static fn() => ns(-1), '-0.000000001'];
        yield 'us' => [static fn() => us(1.5), '0.000001500'];
        yield 'ms' => [static fn() => ms(250), '0.250000000'];
        yield 'negative ms' => [static fn() => ms(-1500), '-1.500000000'];
        yield 'sec' => [static fn() => sec(90), '90.000000000'];
        yield 'float sec' => [static fn() => sec(1.1), '1.100000000'];
        yield 'sec rounds half away from zero' => [static fn() => sec(0.0000000005), '0.000000001'];
        yield 'negative sec rounds half away from zero' => [static fn() => sec(-0.0000000005), '-0.000000001'];
        // round() of PHP 8.3 and older rounds to 15 digits first and leaves a value of 1e15 and above as it is.
        yield 'sec just below a half' => [static fn() => sec(4.9999999999999994e-10), '0.000000000'];
        yield 'sec of 15 digits just below a half' => [static fn() => sec(1.0000000004999998), '1.000000000'];
        yield 'sec above 1e15 ns' => [static fn() => sec(2000000.0000000007), '2000000.000000001'];
        yield 'hours above 1e15 ns' => [static fn() => hours(300.0000000000002), '1080000.000000001'];
        yield 'min int sec' => [static fn() => sec(PHP_INT_MIN), self::OUT_OF_RANGE];
        yield 'float sec above max' => [static fn() => sec(1e10), self::OUT_OF_RANGE];
        yield 'float sec at 2^63 ns' => [static fn() => sec(2.0 ** 63 / 1e9), self::OUT_OF_RANGE];
        yield 'float sec at -2^63 ns' => [static fn() => sec(-2.0 ** 63 / 1e9), self::OUT_OF_RANGE];
        // The nanoseconds of a finite float may overflow to INF.
        yield 'max float sec' => [static fn() => sec(PHP_FLOAT_MAX), self::OUT_OF_RANGE];
        yield 'min float ms' => [static fn() => ms(-PHP_FLOAT_MAX), self::OUT_OF_RANGE];
        yield 'NAN sec' => [static fn() => sec(NAN), 'ValueError: A duration must be finite, NAN given'];
        yield 'INF sec' => [static fn() => sec(INF), 'ValueError: A duration must be finite, INF given'];
        yield 'minutes' => [static fn() => minutes(1.5), '90.000000000'];
        yield 'hours' => [static fn() => hours(0.25), '900.000000000'];
        yield 'int hours' => [static fn() => hours(25), '90000.000000000'];
        yield 'max int hours' => [static fn() => hours(PHP_INT_MAX), self::OUT_OF_RANGE];
        yield 'min int hours' => [static fn() => hours(PHP_INT_MIN), self::OUT_OF_RANGE];
        yield 'negative hours' => [static fn() => hours(-2), '-7200.000000000'];
        yield 'duration' => [
            static fn() => duration(hours: 1, minutes: 2, sec: 3, ms: 4, us: 5, ns: 6),
            '3723.004005006',
        ];
        yield 'duration of mixed signs' => [static fn() => duration(minutes: 1, sec: -90), '-30.000000000'];
        yield 'empty duration' => [static fn() => duration(), '0.000000000'];
        yield 'toNs' => [static fn() => toNs(sec(1.5)), '1500000000'];
        yield 'toUs' => [static fn() => toUs(ns(1999)), '1'];
        yield 'toMs' => [static fn() => toMs(us(1999)), '1'];
        yield 'toMs rounded up' => [static fn() => toMs(us(1001), \RoundingMode::PositiveInfinity), '2'];
        yield 'toSec' => [static fn() => toSec(ms(-1999)), '-1'];
        yield 'toSec rounded down' => [static fn() => toSec(ms(-1001), \RoundingMode::NegativeInfinity), '-2'];
        yield 'toSec of a half to even' => [static fn() => toSec(ms(2500), \RoundingMode::HalfEven), '2'];
        yield 'toMinutes' => [static fn() => toMinutes(sec(119)), '1'];
        yield 'toMinutes of a half to even' => [static fn() => toMinutes(sec(-90), \RoundingMode::HalfEven), '-2'];
        yield 'toMinutes above a half' => [
            static fn() => toMinutes(Duration::fromSeconds(30, 1), \RoundingMode::HalfTowardsZero),
            '1',
        ];
        yield 'toHours' => [static fn() => toHours(minutes(-119)), '-1'];
        yield 'toHours of a half' => [static fn() => toHours(minutes(30), \RoundingMode::HalfOdd), '1'];
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
        yield 'multiply zero by min int' => [static fn() => multiply(ns(0), PHP_INT_MIN), '0.000000000'];
        yield 'multiply above max' => [static fn() => multiply(hours(500_000), 2000), self::OUT_OF_RANGE];
        yield 'multiply long by float' => [static fn() => multiply(sec(2_000_000_000), -1.0), '-2000000000.000000000'];

        yield from \PHP_INT_SIZE === 4 ? self::callsOf32Bits() : self::callsOf64Bits();
    }

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function callsOf64Bits(): iterable
    {
        yield 'min int ns' => [static fn() => ns(PHP_INT_MIN), self::OUT_OF_RANGE];
        yield 'max int ns' => [static fn() => ns(PHP_INT_MAX), self::OUT_OF_RANGE];
        yield 'max sec' => [static fn() => sec(9_223_372_035), '9223372035.000000000'];
        yield 'sec above max' => [static fn() => sec(9_223_372_036), self::OUT_OF_RANGE];
        yield 'negative sec below min' => [static fn() => sec(-9_223_372_036), self::OUT_OF_RANGE];
        yield 'max int sec' => [static fn() => sec(PHP_INT_MAX), self::OUT_OF_RANGE];
        // The float of nanoseconds: they are exact below 2^53.
        yield 'float sec near max' => [static fn() => sec(9_223_372_035.0), '9223372035.000000512'];
        // Above 2^62 nanoseconds a float is a multiple of 1024, and an odd second is not.
        yield 'float sec of an odd second above 2^62 ns' => [
            static fn() => sec(5_000_000_001.5),
            '5000000001.500000256',
        ];
        yield 'toNs of max' => [
            static fn() => toNs(Duration::fromSeconds(9_223_372_035, 999_999_999)),
            '9223372035999999999',
        ];
        yield 'toNs of min' => [
            static fn() => toNs(Duration::fromSeconds(9_223_372_035, 999_999_999)->negate()),
            '-9223372035999999999',
        ];
        yield 'toSec of max rounded up' => [
            static fn() => toSec(Duration::fromSeconds(9_223_372_035, 1), \RoundingMode::AwayFromZero),
            '9223372036',
        ];
        yield 'multiply by min int' => [static fn() => multiply(ns(1), PHP_INT_MIN), self::OUT_OF_RANGE];
    }

    /**
     * An int of nanoseconds holds 2.1 seconds, of microseconds 35 minutes, of milliseconds 24 days.
     *
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function callsOf32Bits(): iterable
    {
        yield 'min int ns' => [static fn() => ns(PHP_INT_MIN), '-2.147483648'];
        yield 'max int ns' => [static fn() => ns(PHP_INT_MAX), '2.147483647'];
        yield 'max int us' => [static fn() => us(PHP_INT_MAX), '2147.483647000'];
        yield 'max int sec' => [static fn() => sec(PHP_INT_MAX), '2147483647.000000000'];
        yield 'float sec to max' => [static fn() => sec(2_147_483_647.5), '2147483647.500000000'];
        yield 'float sec just above max' => [static fn() => sec(2_147_483_648.0), self::OUT_OF_RANGE];
        yield 'max int minutes' => [static fn() => minutes(35_791_394), '2147483640.000000000'];
        yield 'minutes above max' => [static fn() => minutes(35_791_395), self::OUT_OF_RANGE];
        yield 'toNs of max int' => [static fn() => toNs(ns(PHP_INT_MAX)), '2147483647'];
        yield 'toNs above max int' => [static fn() => toNs(ns(PHP_INT_MAX)->add(ns(1))), self::NOT_INT];
        // PHP_INT_MIN would fit, but the magnitude is rounded first.
        yield 'toNs of min int' => [static fn() => toNs(ns(PHP_INT_MIN)), self::NOT_INT];
        yield 'toNs of max int below zero' => [static fn() => toNs(ns(-PHP_INT_MAX)), '-2147483647'];
        yield 'toUs to max int' => [static fn() => toUs(Duration::fromSeconds(2147, 483_647_999)), '2147483647'];
        yield 'toUs rounded above max int' => [
            static fn() => toUs(Duration::fromSeconds(2147, 483_647_001), \RoundingMode::PositiveInfinity),
            self::NOT_INT,
        ];
        yield 'toMs to max int' => [static fn() => toMs(sec(2_147_483)), '2147483000'];
        yield 'toMs above max int' => [static fn() => toMs(sec(2_147_484)), self::NOT_INT];
        yield 'toSec of max' => [static fn() => toSec(Duration::fromSeconds(PHP_INT_MAX, 999_999_999)), '2147483647'];
        yield 'toSec of max rounded up' => [
            static fn() => toSec(Duration::fromSeconds(PHP_INT_MAX, 1), \RoundingMode::AwayFromZero),
            self::NOT_INT,
        ];
        yield 'toHours of max' => [static fn() => toHours(sec(PHP_INT_MAX)), '596523'];
        yield 'multiply by min int' => [static fn() => multiply(ns(1), PHP_INT_MIN), '-2.147483648'];
        yield 'multiply max by 2' => [static fn() => multiply(sec(PHP_INT_MAX), 2), self::OUT_OF_RANGE];
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
        yield 'max' => \PHP_INT_SIZE === 4
            ? [Duration::fromSeconds(PHP_INT_MAX, 999_999_999), '596523h14m7.999999999s']
            : [Duration::fromSeconds(9_223_372_035, 999_999_999), '2562047h47m15.999999999s'];
    }

    #[DataProvider('formats')]
    public function formatsLikeGo(Duration $duration, string $expected): void
    {
        Assert::same(format($duration), $expected);
    }

    public function roundsAsRoundOfFloats(): void
    {
        // @phpstan-ignore smaller.alwaysFalse (PHPStan takes the PHP of the monorepo, the package runs on 8.2)
        if (\PHP_VERSION_ID < 80400) {
            throw new SkipTest('round() takes a \RoundingMode since PHP 8.4.');
        }

        // A remainder of nanoseconds, of a second and of a minute.
        foreach (\RoundingMode::cases() as $rounding) {
            for ($thousandths = -3500; $thousandths <= 3500; $thousandths += 250) {
                $expected = (int) round($thousandths / 1000, mode: $rounding);
                $message = "{$thousandths} / 1000, {$rounding->name}";

                Assert::same(toUs(ns($thousandths), $rounding), $expected, $message);
                Assert::same(toSec(ms($thousandths), $rounding), $expected, $message);
                Assert::same(toMinutes(ms($thousandths * 60), $rounding), $expected, $message);
            }
        }
    }
}
