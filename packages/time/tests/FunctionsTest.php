<?php

declare(strict_types=1);

namespace Thesis\Time;

use Random\Engine\Mt19937;
use Random\Randomizer;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Data\DataProvider;
use Testo\Test;
use Time\Duration;
use const Thesis\Time\Internal\MAX_SECONDS;

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
        yield 'toUs' => [static fn() => toUs(ns(1999), \RoundingMode::TowardsZero), '1'];
        yield 'toMs' => [static fn() => toMs(us(1999), \RoundingMode::TowardsZero), '1'];
        yield 'toMs rounded up' => [static fn() => toMs(us(1001), \RoundingMode::PositiveInfinity), '2'];
        yield 'toSec' => [static fn() => toSec(ms(-1999), \RoundingMode::TowardsZero), '-1'];
        yield 'toSec rounded down' => [static fn() => toSec(ms(-1001), \RoundingMode::NegativeInfinity), '-2'];
        yield 'toSec of a half to even' => [static fn() => toSec(ms(2500), \RoundingMode::HalfEven), '2'];
        yield 'toMinutes' => [static fn() => toMinutes(sec(119), \RoundingMode::TowardsZero), '1'];
        yield 'toMinutes of a half to even' => [static fn() => toMinutes(sec(-90), \RoundingMode::HalfEven), '-2'];
        yield 'toMinutes above a half' => [
            static fn() => toMinutes(Duration::fromSeconds(30, 1), \RoundingMode::HalfTowardsZero),
            '1',
        ];
        yield 'toHours' => [static fn() => toHours(minutes(-119), \RoundingMode::TowardsZero), '-1'];
        yield 'toHours of a half' => [static fn() => toHours(minutes(30), \RoundingMode::HalfOdd), '1'];
        yield 'toUsFloat' => [static fn() => toUsFloat(ns(1500)), '1.5'];
        yield 'negative toUsFloat' => [static fn() => toUsFloat(sec(-1)->sub(ns(1))), '-1000000.001'];
        yield 'toMsFloat' => [static fn() => toMsFloat(us(1500)), '1.5'];
        yield 'negative toMsFloat' => [static fn() => toMsFloat(ns(-1)), '-1.0E-6'];
        yield 'toSecFloat' => [static fn() => toSecFloat(ms(1500)), '1.5'];
        yield 'negative toSecFloat' => [static fn() => toSecFloat(ns(-1)), '-1.0E-9'];
        yield 'toMinutesFloat' => [static fn() => toMinutesFloat(sec(90)), '1.5'];
        yield 'negative toMinutesFloat' => [static fn() => toMinutesFloat(sec(-90)), '-1.5'];
        yield 'toHoursFloat' => [static fn() => toHoursFloat(minutes(90)), '1.5'];
        yield 'negative toHoursFloat' => [static fn() => toHoursFloat(minutes(-90)), '-1.5'];
        // A float of seconds divided by 60 rounds twice: 0.009000000000000001 and 0.020999999999999998.
        yield 'toMinutesFloat rounds once' => [static fn() => toMinutesFloat(minutes(0.009)), '0.009'];
        yield 'toHoursFloat rounds once' => [static fn() => toHoursFloat(hours(0.021)), '0.021'];
        yield 'toSecFloat rounds once' => [static fn() => toSecFloat(sec(1.118)), '1.118'];
        // Above 2^53 nanoseconds the whole units stay exact.
        yield 'toSecFloat above 2^53 ns' => [
            static fn() => toSecFloat(Duration::fromSeconds(10_000_000, 500_000_001)),
            '10000000.500000002',
        ];
        yield 'toHoursFloat above 2^53 ns' => [static fn() => toHoursFloat(minutes(200_000.5)), '3333.3416666666667'];
        yield 'isZero' => [static fn() => [isZero(ns(0)), isZero(ns(1)), isZero(ns(-1))], '[true, false, false]'];
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
        yield 'toUs to max int' => [
            static fn() => toUs(Duration::fromSeconds(2147, 483_647_999), \RoundingMode::TowardsZero),
            '2147483647',
        ];
        yield 'toUs rounded above max int' => [
            static fn() => toUs(Duration::fromSeconds(2147, 483_647_001), \RoundingMode::PositiveInfinity),
            self::NOT_INT,
        ];
        yield 'toMs to max int' => [static fn() => toMs(sec(2_147_483), \RoundingMode::TowardsZero), '2147483000'];
        yield 'toMs above max int' => [static fn() => toMs(sec(2_147_484), \RoundingMode::TowardsZero), self::NOT_INT];
        yield 'toSec of max' => [
            static fn() => toSec(Duration::fromSeconds(PHP_INT_MAX, 999_999_999), \RoundingMode::TowardsZero),
            '2147483647',
        ];
        yield 'toSec of max rounded up' => [
            static fn() => toSec(Duration::fromSeconds(PHP_INT_MAX, 1), \RoundingMode::AwayFromZero),
            self::NOT_INT,
        ];
        yield 'toHours of max' => [static fn() => toHours(sec(PHP_INT_MAX), \RoundingMode::TowardsZero), '596523'];
        // The float conversions never throw: the products wider than int are floats.
        yield 'toUsFloat of max' => [
            static fn() => toUsFloat(Duration::fromSeconds(PHP_INT_MAX, 999_999_999)),
            '2147483648000000.0',
        ];
        yield 'toMsFloat above max int' => [static fn() => toMsFloat(sec(2_147_484)), '2147484000.0'];
        yield 'toMinutesFloat of max' => [
            static fn() => toMinutesFloat(Duration::fromSeconds(PHP_INT_MAX, 999_999_999)),
            '35791394.13333333',
        ];
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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function strings(): iterable
    {
        yield 'zero without a unit' => ['0', '0.000000000'];
        yield 'zero with a plus' => ['+0', '0.000000000'];
        yield 'zero with a minus' => ['-0', '0.000000000'];
        yield 'negative zero' => ['-0s', '0.000000000'];
        yield 'ns' => ['15ns', '0.000000015'];
        yield 'us' => ['1us', '0.000001000'];
        yield 'us with the micro sign' => ["1\u{B5}s", '0.000001000'];
        yield 'us with the Greek mu' => ["1\u{3BC}s", '0.000001000'];
        yield 'ms' => ['2.5ms', '0.002500000'];
        yield 'sec' => ['1.5s', '1.500000000'];
        yield 'minutes' => ['1m30s', '90.000000000'];
        yield 'hours' => ['1h2m3.5s', '3723.500000000'];
        yield 'negative' => ['-1.5s', '-1.500000000'];
        yield 'negative of several units' => ['-1m30s', '-90.000000000'];
        yield 'plus' => ['+5s', '5.000000000'];
        yield 'no digits before the point' => ['.5s', '0.500000000'];
        yield 'no digits after the point' => ['5.s', '5.000000000'];
        yield 'leading zeros' => ['007s', '7.000000000'];
        yield 'a fraction of hours' => ['1.5h', '5400.000000000'];
        yield 'a fraction of minutes' => ['0.1m', '6.000000000'];
        yield 'a fraction of minutes with a carry' => ['0.75m', '45.000000000'];
        yield 'a nanosecond after a second' => ['1.000000001s', '1.000000001'];
        yield 'units in any order' => ['1s1h', '3601.000000000'];
        yield 'a unit twice' => ['1s1.5s', '2.500000000'];
        // Each number is rounded to nanoseconds: a float of 0.1 + 0.2 is 0.30000000000000004.
        yield 'tenths' => ['0.1s0.2s', '0.300000000'];
        yield 'below a half of ns' => ['0.4ns', '0.000000000'];
        yield 'a half of ns' => ['0.5ns', '0.000000001'];
        yield 'a negative half of ns' => ['-0.5ns', '-0.000000001'];
        yield 'above a half of ns rounds to a second' => ['1.9999999996s', '2.000000000'];
        yield '3.6 ns in hours' => ['0.000000000001h', '0.000000004'];
        yield '0.6 ns in minutes' => ['0.00000000001m', '0.000000001'];
        yield '0.48 ns in minutes' => ['0.000000000008m', '0.000000000'];
        yield 'more digits than a float holds' => ['0.33333333333333333333s', '0.333333333'];
        yield 'hours above any int' => ['999999999999999999999999999999h', self::OUT_OF_RANGE];
        yield 'zeros above any int' => ['000000000000000000000000000001h', '3600.000000000'];
        yield 'ns above any float' => [str_repeat('9', 400) . 'ns', self::OUT_OF_RANGE];

        foreach ([
            '', '-', '+', '1', '1.5', '00', '0.0', 's', '.s', '-.s', '1x', '1 s', ' 1s', '1s ', "1s\n", "1s\0", '1S',
            '1d', '1w', '--1s', '1s-1s', '1s+1s', '1e3s', '1,5s', '1h30', '1ms1', '1.2.3s', "1\u{B5}", 'PT1S',
        ] as $string) {
            yield var_export($string, true) => [
                $string,
                \sprintf(
                    'Time\TimeException: A duration must be like 1h30m, 1.5s or 250ms, %s given',
                    var_export($string, true),
                ),
            ];
        }

        yield from \PHP_INT_SIZE === 4 ? self::stringsOf32Bits() : self::stringsOf64Bits();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function stringsOf64Bits(): iterable
    {
        yield 'max' => ['2562047h47m15.999999999s', '9223372035.999999999'];
        yield 'min' => ['-2562047h47m15.999999999s', '-9223372035.999999999'];
        yield 'a fraction of hours near max' => ['2562047.5h', '9223371000.000000000'];
        yield 'sec above max' => ['9223372036s', self::OUT_OF_RANGE];
        yield 'a sum above max' => ['9223372035s1s', self::OUT_OF_RANGE];
        yield 'a sum below min' => ['-9223372035s1s', self::OUT_OF_RANGE];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function stringsOf32Bits(): iterable
    {
        yield 'max' => ['596523h14m7.999999999s', '2147483647.999999999'];
        yield 'min' => ['-596523h14m7.999999999s', '-2147483647.999999999'];
        yield 'ns above max int' => ['3000000000ns', '3.000000000'];
        yield 'sec above max' => ['2147483648s', self::OUT_OF_RANGE];
        yield 'a sum above max' => ['2147483647s1s', self::OUT_OF_RANGE];
        yield 'a sum below min' => ['-2147483647s1s', self::OUT_OF_RANGE];
    }

    #[DataProvider('strings')]
    public function parsesLikeGo(string $string, string $expected): void
    {
        Assert::same(outcome(static fn() => parse($string)), $expected);
    }

    public function parsesWhatFormatWrites(): void
    {
        $random = new Randomizer(new Mt19937(1));

        for ($case = 0; $case < 2000; ++$case) {
            // Of every length, a half without seconds: format() changes the unit below a second, a millisecond
            // and a microsecond.
            $duration = signed(
                seconds: $random->getInt(0, 1) * $random->getInt(0, intdiv(MAX_SECONDS, 10 ** $random->getInt(0, 9))),
                nanoseconds: $random->getInt(0, 10 ** $random->getInt(0, 9) - 1),
                negative: $random->getInt(0, 1) === 1,
            );
            $string = format($duration);

            Assert::same(describe(parse($string)), describe($duration), $string);
        }
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
