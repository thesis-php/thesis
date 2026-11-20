<?php

declare(strict_types=1);

namespace Thesis\Time;

use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Test;
use Time\Duration;
use Time\TimeException;
use const Thesis\Time\Internal\MAX_SECONDS;

/**
 * Values taken from the class of PHP 8.6, 64-bit and 32-bit: the test passes against it and against the polyfill.
 */
#[Test]
final class DurationTest
{
    private const OUT_OF_RANGE = \PHP_INT_SIZE === 4
        ? 'Time\TimeException: The maximum representable range is 2_147_483_647 seconds (roughly 68 years)'
        : 'Time\TimeException: The maximum representable range is 9_223_372_035 seconds (roughly 292 years)';
    private const PARSE_FAILURE = 'Time\TimeException: The ISO 8601 duration string could not be parsed';
    private const DAYS_FOUND = 'Time\TimeException: The ISO 8601 duration string may only contain the time (T) aspect';
    private const ONLY_PERIOD = 'Time\TimeException: The ISO 8601 duration string may only contain the period (P) aspect';
    private const MISSING_PERIOD = 'Time\TimeException: The ISO 8601 duration string is missing the period (P) aspect';
    private const INVALID_DATA = 'Exception: Invalid serialization data for Time\Duration object';

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function factories(): iterable
    {
        yield 'seconds' => [static fn() => Duration::fromSeconds(90), '90.000000000'];
        yield 'seconds and nanoseconds' => [static fn() => Duration::fromSeconds(1, 5), '1.000000005'];
        yield 'negative seconds' => [
            static fn() => Duration::fromSeconds(-1),
            'ValueError: Time\Duration::fromSeconds(): Argument #1 ($seconds) must be greater than or equal to 0',
        ];
        yield 'negative nanoseconds' => [
            static fn() => Duration::fromSeconds(0, -1),
            'ValueError: Time\Duration::fromSeconds(): Argument #2 ($nanoseconds) must be greater than or equal to 0',
        ];
        yield 'a second of nanoseconds' => [
            static fn() => Duration::fromSeconds(0, 1_000_000_000),
            'ValueError: Time\Duration::fromSeconds(): Argument #2 ($nanoseconds) must be less than 1_000_000_000',
        ];
        yield 'nanoseconds' => [static fn() => Duration::fromNanoseconds(1_500_000_001), '1.500000001'];
        yield 'microseconds' => [static fn() => Duration::fromMicroseconds(1_500_001), '1.500001000'];
        yield 'milliseconds' => [static fn() => Duration::fromMilliseconds(1500), '1.500000000'];
        yield 'minutes' => [static fn() => Duration::fromMinutes(90), '5400.000000000'];
        yield 'max minutes' => [static fn() => Duration::fromMinutes(PHP_INT_MAX), self::OUT_OF_RANGE];
        yield 'hours' => [static fn() => Duration::fromHours(25), '90000.000000000'];
        yield 'max hours' => [static fn() => Duration::fromHours(PHP_INT_MAX), self::OUT_OF_RANGE];

        $negative = [
            'nanoseconds' => static fn() => Duration::fromNanoseconds(-1),
            'microseconds' => static fn() => Duration::fromMicroseconds(-1),
            'milliseconds' => static fn() => Duration::fromMilliseconds(-1),
            'minutes' => static fn() => Duration::fromMinutes(-1),
            'hours' => static fn() => Duration::fromHours(-1),
        ];

        foreach ($negative as $unit => $call) {
            yield "negative {$unit}" => [
                $call,
                \sprintf(
                    'ValueError: Time\Duration::from%s(): Argument #1 ($%s) must be greater than or equal to 0',
                    ucfirst($unit),
                    $unit,
                ),
            ];
        }

        yield from \PHP_INT_SIZE === 4 ? self::factoriesOf32Bits() : self::factoriesOf64Bits();
    }

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function factoriesOf64Bits(): iterable
    {
        yield 'max' => [static fn() => Duration::fromSeconds(9_223_372_035, 999_999_999), '9223372035.999999999'];
        yield 'seconds above max' => [static fn() => Duration::fromSeconds(9_223_372_036), self::OUT_OF_RANGE];
        yield 'max nanoseconds' => [static fn() => Duration::fromNanoseconds(PHP_INT_MAX), self::OUT_OF_RANGE];
        yield 'microseconds to max' => [
            static fn() => Duration::fromMicroseconds(9_223_372_035_999_999),
            '9223372035.999999000',
        ];
        yield 'microseconds above max' => [
            static fn() => Duration::fromMicroseconds(9_223_372_036_000_000),
            self::OUT_OF_RANGE,
        ];
        yield 'max milliseconds' => [static fn() => Duration::fromMilliseconds(PHP_INT_MAX), self::OUT_OF_RANGE];
        yield 'minutes to max' => [static fn() => Duration::fromMinutes(153_722_867), '9223372020.000000000'];
        yield 'minutes above max' => [static fn() => Duration::fromMinutes(153_722_868), self::OUT_OF_RANGE];
        yield 'hours to max' => [static fn() => Duration::fromHours(2_562_047), '9223369200.000000000'];
        yield 'hours above max' => [static fn() => Duration::fromHours(2_562_048), self::OUT_OF_RANGE];
    }

    /**
     * Every int of seconds is in the range: it ends at PHP_INT_MAX.
     *
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function factoriesOf32Bits(): iterable
    {
        yield 'max' => [static fn() => Duration::fromSeconds(PHP_INT_MAX, 999_999_999), '2147483647.999999999'];
        yield 'max nanoseconds' => [static fn() => Duration::fromNanoseconds(PHP_INT_MAX), '2.147483647'];
        yield 'max microseconds' => [static fn() => Duration::fromMicroseconds(PHP_INT_MAX), '2147.483647000'];
        yield 'max milliseconds' => [static fn() => Duration::fromMilliseconds(PHP_INT_MAX), '2147483.647000000'];
        yield 'minutes to max' => [static fn() => Duration::fromMinutes(35_791_394), '2147483640.000000000'];
        yield 'minutes above max' => [static fn() => Duration::fromMinutes(35_791_395), self::OUT_OF_RANGE];
        yield 'hours to max' => [static fn() => Duration::fromHours(596_523), '2147482800.000000000'];
        yield 'hours above max' => [static fn() => Duration::fromHours(596_524), self::OUT_OF_RANGE];
    }

    /**
     * @param \Closure(): mixed $call
     */
    #[DataProvider('factories')]
    #[DataProvider('arithmetic')]
    #[DataProvider('isoStrings')]
    #[DataProvider('serializations')]
    public function behavesAsPhp86(\Closure $call, string $expected): void
    {
        Assert::same(outcome($call), $expected);
    }

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function arithmetic(): iterable
    {
        yield 'negate' => [static fn() => Duration::fromMilliseconds(1500)->negate(), '-1.500000000'];
        yield 'negate zero' => [static fn() => Duration::fromSeconds(0)->negate(), '0.000000000'];
        yield 'negate twice' => [static fn() => Duration::fromSeconds(1)->negate()->negate(), '1.000000000'];
        yield 'absolute' => [static fn() => signed(5, negative: true)->absolute(), '5.000000000'];
        yield 'add with carry' => [static fn() => signed(1, 600_000_000)->add(signed(0, 600_000_000)), '2.200000000'];
        yield 'add opposite' => [static fn() => signed(1)->add(signed(2, negative: true)), '-1.000000000'];
        yield 'sub to zero' => [static fn() => signed(1)->sub(signed(1)), '0.000000000'];
        yield 'sub below zero' => [static fn() => signed(0, 1)->sub(signed(0, 2)), '-0.000000001'];
        yield 'sub negatives' => [
            static fn() => signed(1, negative: true)->sub(signed(3, negative: true)),
            '2.000000000',
        ];
        yield 'multiply by zero' => [static fn() => signed(1, negative: true)->multiplyBy(0), '0.000000000'];
        yield 'multiply negative' => [static fn() => signed(1, negative: true)->multiplyBy(3), '-3.000000000'];
        yield 'multiply by negative' => [
            static fn() => signed(1)->multiplyBy(-1),
            'ValueError: Time\Duration::multiplyBy(): Argument #1 ($factor) must be greater than or equal to 0',
        ];
        yield 'divide truncates' => [static fn() => signed(1)->divideBy(3), '0.333333333'];
        yield 'divide carries the remainder' => [static fn() => signed(5, 5)->divideBy(2), '2.500000002'];
        yield 'divide negative' => [static fn() => signed(1, negative: true)->divideBy(3), '-0.333333333'];
        yield 'divide to zero is not negative' => [static fn() => signed(0, 1, true)->divideBy(2), '0.000000000'];
        yield 'divide by zero' => [static fn() => signed(1)->divideBy(0), 'DivisionByZeroError: Division by zero'];
        yield 'divide by negative' => [
            static fn() => signed(1)->divideBy(-1),
            'ValueError: Time\Duration::divideBy(): Argument #1 ($divisor) must be greater than or equal to 0',
        ];
        yield 'compare negatives' => [
            static fn() => Duration::compare(signed(1, negative: true), signed(2, negative: true)),
            '1',
        ];
        yield 'compare nanoseconds' => [static fn() => Duration::compare(signed(0, 4), signed(0, 5)), '-1'];
        yield 'compare equal' => [static fn() => Duration::compare(signed(1), Duration::fromMilliseconds(1000)), '0'];
        yield 'multiply nanoseconds with a carry' => [
            static fn() => signed(1, 500_000_000)->multiplyBy(4),
            '6.000000000',
        ];
        yield 'divide nanoseconds with a remainder' => [
            static fn() => signed(999_999, 999_999_999)->divideBy(1_000_000),
            '0.999999999',
        ];

        yield from \PHP_INT_SIZE === 4 ? self::arithmeticOf32Bits() : self::arithmeticOf64Bits();
    }

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function arithmeticOf64Bits(): iterable
    {
        yield 'add to zero' => [
            static fn() => signed(9_223_372_035, 999_999_999, true)->add(signed(9_223_372_035, 999_999_999)),
            '0.000000000',
        ];
        yield 'add above max' => [
            static fn() => signed(9_223_372_035, 999_999_999)->add(Duration::fromNanoseconds(1)),
            self::OUT_OF_RANGE,
        ];
        yield 'sub below min' => [
            static fn() => signed(9_223_372_035, negative: true)->sub(signed(1)),
            self::OUT_OF_RANGE,
        ];
        yield 'multiply to max' => [static fn() => signed(3_074_457_345)->multiplyBy(3), '9223372035.000000000'];
        yield 'multiply nanoseconds' => [
            static fn() => signed(0, 999_999_999)->multiplyBy(9_223_372_035),
            '9223372025.776627965',
        ];
        yield 'multiply above max' => [
            static fn() => signed(9_223_372_035, 999_999_999)->multiplyBy(1_999_999_999),
            self::OUT_OF_RANGE,
        ];
        yield 'multiply above 2^64' => [
            static fn() => signed(3)->multiplyBy(PHP_INT_MAX),
            'Time\TimeException: Failed to create a Time\Duration: Integer Overflow',
        ];
        yield 'multiply above 2^64 by carry' => [
            static fn() => signed(2, 999_999_999)->multiplyBy(PHP_INT_MAX),
            'Time\TimeException: Failed to create a Time\Duration: Integer Overflow',
        ];
        yield 'multiply nanoseconds above 2^64' => [
            static fn() => signed(9_223_372_035, 999_999_999)->multiplyBy(2_000_000_001),
            'Time\TimeException: Failed to create a Time\Duration: Integer Overflow',
        ];
        yield 'multiply to exactly 2^64 - 1' => [
            static fn() => signed(4_294_967_297)->multiplyBy(4_294_967_295),
            self::OUT_OF_RANGE,
        ];
        yield 'multiply to 2^64 + 2^32' => [
            static fn() => signed(4_294_967_297)->multiplyBy(4_294_967_296),
            'Time\TimeException: Failed to create a Time\Duration: Integer Overflow',
        ];
        yield 'divide max' => [static fn() => signed(9_223_372_035, 999_999_999)->divideBy(7), '1317624576.571428571'];
        yield 'divide max by a larger number' => [
            static fn() => signed(9_223_372_035, 999_999_999)->divideBy(9_223_372_036),
            '0.999999999',
        ];
        yield 'multiply a nanosecond by max' => [
            static fn() => signed(0, 1)->multiplyBy(PHP_INT_MAX),
            self::OUT_OF_RANGE,
        ];
        yield 'compare extremes' => [
            static fn() => Duration::compare(signed(9_223_372_035, negative: true), signed(9_223_372_034, 1, true)),
            '-1',
        ];
    }

    /**
     * No "Integer Overflow": the seconds and the factor are below 2^31, their product below 2^62.
     *
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function arithmeticOf32Bits(): iterable
    {
        yield 'add to zero' => [
            static fn() => signed(PHP_INT_MAX, 999_999_999, true)->add(signed(PHP_INT_MAX, 999_999_999)),
            '0.000000000',
        ];
        yield 'add above max' => [
            static fn() => signed(PHP_INT_MAX, 999_999_999)->add(Duration::fromNanoseconds(1)),
            self::OUT_OF_RANGE,
        ];
        yield 'add max to max' => [static fn() => signed(PHP_INT_MAX)->add(signed(PHP_INT_MAX)), self::OUT_OF_RANGE];
        yield 'add with carry to max' => [
            static fn() => signed(PHP_INT_MAX - 1, 600_000_000)->add(signed(0, 400_000_000)),
            '2147483647.000000000',
        ];
        yield 'sub below min' => [
            static fn() => signed(PHP_INT_MAX, negative: true)->sub(signed(1)),
            self::OUT_OF_RANGE,
        ];
        yield 'sub min from max' => [
            static fn() => signed(PHP_INT_MAX)->sub(signed(PHP_INT_MAX, negative: true)),
            self::OUT_OF_RANGE,
        ];
        yield 'multiply to max' => [static fn() => signed(715_827_882)->multiplyBy(3), '2147483646.000000000'];
        yield 'multiply nanoseconds' => [
            static fn() => signed(0, 999_999_999)->multiplyBy(PHP_INT_MAX),
            '2147483644.852516353',
        ];
        yield 'multiply a nanosecond by max' => [static fn() => signed(0, 1)->multiplyBy(PHP_INT_MAX), '2.147483647'];
        yield 'multiply above max' => [
            static fn() => signed(PHP_INT_MAX, 999_999_999)->multiplyBy(1_999_999_999),
            self::OUT_OF_RANGE,
        ];
        yield 'multiply by max' => [static fn() => signed(3)->multiplyBy(PHP_INT_MAX), self::OUT_OF_RANGE];
        yield 'multiply above max by carry' => [
            static fn() => signed(1, 1)->multiplyBy(PHP_INT_MAX - 1),
            self::OUT_OF_RANGE,
        ];
        yield 'divide max' => [static fn() => signed(PHP_INT_MAX, 999_999_999)->divideBy(7), '306783378.285714285'];
        yield 'divide max by max' => [
            static fn() => signed(PHP_INT_MAX, 999_999_999)->divideBy(PHP_INT_MAX),
            '1.000000000',
        ];
        yield 'divide below max by max' => [
            static fn() => signed(PHP_INT_MAX - 1, 999_999_999)->divideBy(PHP_INT_MAX),
            '0.999999999',
        ];
        yield 'compare extremes' => [
            static fn() => Duration::compare(signed(PHP_INT_MAX, negative: true), signed(PHP_INT_MAX - 1, 1, true)),
            '-1',
        ];
    }

    public function comparesWithOperators(): void
    {
        $minusTwo = signed(2, negative: true);
        $minusOne = signed(1, negative: true);
        $tiny = signed(0, 1);
        $one = signed(1);

        Assert::true($minusTwo < $minusOne);
        Assert::true($minusOne < $tiny);
        Assert::true($tiny < $one);
        Assert::true($one > $minusTwo);
        Assert::same($minusTwo <=> $minusOne, -1);
        Assert::same($one <=> $tiny, 1);
        // Assert::equals(), not ==, which the fixer turns into ===: see "Tests" in AGENTS.md.
        Assert::equals($one, Duration::fromMilliseconds(1000));
        Assert::notEquals($one, $tiny);
        Assert::true($one >= Duration::fromMilliseconds(1000));
        Assert::true(signed(MAX_SECONDS, negative: true) < signed(MAX_SECONDS - 1, 999_999_999, true));
        Assert::true(signed(MAX_SECONDS - 1, 999_999_999) < signed(MAX_SECONDS));
    }

    public function isOrderedByBuiltInFunctions(): void
    {
        $minusTwo = signed(2, negative: true);
        $minusTiny = signed(0, 999_999_999, negative: true);
        $tiny = signed(0, 1);
        $one = signed(1);
        $durations = [$one, $minusTiny, $tiny, $minusTwo];

        Assert::same(min($one, $minusTiny, $tiny, $minusTwo), $minusTwo);
        Assert::same(max($minusTwo, $tiny, $one, $minusTiny), $one);
        Assert::same(min($durations), $minusTwo);
        Assert::same(max($durations), $one);

        sort($durations);

        Assert::same($durations, [$minusTwo, $minusTiny, $tiny, $one]);
    }

    public function hasExtensibleException(): void
    {
        $exception = new \ReflectionClass(TimeException::class);

        Assert::false($exception->isFinal());
        Assert::true($exception->isSubclassOf(\Exception::class));
    }

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function isoStrings(): iterable
    {
        $cases = [
            'PT1S' => '1.000000000',
            'PT0S' => '0.000000000',
            'PT1H2M3S' => '3723.000000000',
            'PT1M90S' => '150.000000000',
            'PT25H' => '90000.000000000',
            'PT1H2S' => '3602.000000000',
            'PT01S' => '1.000000000',
            'P0D' => '0.000000000',
            'P0W' => '0.000000000',
            'P0Y0M0DT1S' => '1.000000000',
            'P0DT1H1M1S' => '3661.000000000',
            'P0000-00-00T00:00:01' => '1.000000000',
            'P0000-00-00T01:02:03' => '3723.000000000',
            'PT153722868M' => self::OUT_OF_RANGE,
            'PT999999999999H' => self::OUT_OF_RANGE,
            'PT999999999999S' => self::OUT_OF_RANGE,
            'P999999999999W' => self::DAYS_FOUND,
            ' PT1S' => '1.000000000',
            "PT1S\n" => '1.000000000',
            "PT1S\t " => '1.000000000',
            "\v\fPT1S\r" => '1.000000000',
            'PT1S PT2S' => '2.000000000',
            'PT1S/' => '1.000000000',
            '/PT1S' => '1.000000000',
            'P1D' => self::DAYS_FOUND,
            'P1W' => self::DAYS_FOUND,
            'P1Y' => self::DAYS_FOUND,
            'P1M' => self::DAYS_FOUND,
            'P1DT1S' => self::DAYS_FOUND,
            'P0000-00-01T00:00:00' => self::DAYS_FOUND,
            'P0000-01-00T00:00:00' => self::DAYS_FOUND,
            'P0001-00-00T00:00:00' => self::DAYS_FOUND,
            'P1W0D' => self::DAYS_FOUND,
            'R5/PT1S' => self::ONLY_PERIOD,
            '2008-01-01T00:00:00Z/PT1S' => self::ONLY_PERIOD,
            '20080101T000000Z/PT1S' => self::ONLY_PERIOD,
            'PT1S/2008-01-01T00:00:00Z' => self::ONLY_PERIOD,
            '2008-01-01T00:00:00Z' => self::MISSING_PERIOD,
            ' ' => self::MISSING_PERIOD,
            "\v " => self::MISSING_PERIOD,
            '' => self::PARSE_FAILURE,
            "\r" => self::PARSE_FAILURE,
            'P' => self::PARSE_FAILURE,
            'PT' => self::PARSE_FAILURE,
            'P0DT' => self::PARSE_FAILURE,
            'PT0.5S' => self::PARSE_FAILURE,
            'PT1,5S' => self::PARSE_FAILURE,
            '-PT1S' => self::PARSE_FAILURE,
            'pt1s' => self::PARSE_FAILURE,
            'PT1Sx' => self::PARSE_FAILURE,
            'PT1H1H' => self::PARSE_FAILURE,
            'PT1S1M' => self::PARSE_FAILURE,
            'PT1D' => self::PARSE_FAILURE,
            'R/PT1S' => self::PARSE_FAILURE,
            'PT9999999999999S' => self::PARSE_FAILURE,
            "PT1S\0" => self::PARSE_FAILURE,
            "PT1S\rPT1S" => self::PARSE_FAILURE,
        ];

        $cases += \PHP_INT_SIZE === 4 ? [
            'PT2147483647S' => '2147483647.000000000',
            'PT2147483648S' => self::OUT_OF_RANGE,
            'PT596523H847S' => '2147483647.000000000',
            'PT596523H848S' => self::OUT_OF_RANGE,
            'PT9223372035S' => self::OUT_OF_RANGE,
        ] : [
            'PT9223372035S' => '9223372035.000000000',
            'PT2562047H2835S' => '9223372035.000000000',
            'PT2562047H2836S' => self::OUT_OF_RANGE,
        ];

        foreach ($cases as $string => $expected) {
            yield json_encode($string, JSON_THROW_ON_ERROR) => [
                static fn() => Duration::fromIso8601DurationString($string),
                $expected,
            ];
        }
    }

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function serializations(): iterable
    {
        $data = static fn(string $seconds, string $nanoseconds, string $negative) => \sprintf(
            'O:13:"Time\Duration":3:{s:7:"seconds";%s;s:11:"nanoseconds";%s;s:8:"negative";%s;}',
            $seconds,
            $nanoseconds,
            $negative,
        );

        yield 'serialize' => [
            static fn() => serialize(signed(1, 2, true)),
            var_export($data('i:1', 'i:2', 'b:1'), true),
        ];
        yield 'unserialize' => [static fn() => unserialize($data('i:1', 'i:2', 'b:1')), '-1.000000002'];
        yield 'unserialize in another order' => [
            static fn() => unserialize('O:13:"Time\Duration":3:{s:11:"nanoseconds";i:2;s:7:"seconds";i:1;s:8:"negative";b:0;}'),
            '1.000000002',
        ];
        yield 'unserialize max' => [
            static fn() => unserialize($data(\sprintf('i:%d', MAX_SECONDS), 'i:999999999', 'b:0')),
            \sprintf('%d.999999999', MAX_SECONDS),
        ];
        // On 32-bit PHP the number is a float above PHP_INT_MAX, which the int property rejects.
        yield 'unserialize above max' => [
            static fn() => unserialize($data(\PHP_INT_SIZE === 4 ? 'i:2147483648' : 'i:9223372036', 'i:0', 'b:0')),
            self::INVALID_DATA . ' < ' . (\PHP_INT_SIZE === 4
                ? 'TypeError: Cannot assign float to property Time\Duration::$seconds of type int'
                : self::OUT_OF_RANGE),
        ];
        yield 'unserialize negative zero' => [
            static fn() => unserialize($data('i:0', 'i:0', 'b:1')),
            self::INVALID_DATA,
        ];
        yield 'unserialize negative seconds' => [
            static fn() => unserialize($data('i:-1', 'i:0', 'b:0')),
            self::INVALID_DATA,
        ];
        yield 'unserialize negative nanoseconds' => [
            static fn() => unserialize($data('i:1', 'i:-1', 'b:0')),
            self::INVALID_DATA,
        ];
        yield 'unserialize a second of nanoseconds' => [
            static fn() => unserialize($data('i:0', 'i:1000000000', 'b:0')),
            self::INVALID_DATA,
        ];
        yield 'unserialize float' => [
            static fn() => unserialize($data('d:1', 'i:0', 'b:0')),
            self::INVALID_DATA . ' < TypeError: Cannot assign float to property Time\Duration::$seconds of type int',
        ];
        yield 'unserialize int as bool' => [
            static fn() => unserialize($data('i:1', 'i:0', 'i:1')),
            self::INVALID_DATA . ' < TypeError: Cannot assign int to property Time\Duration::$negative of type bool',
        ];
        yield 'unserialize missing' => [
            static fn() => unserialize('O:13:"Time\Duration":1:{s:7:"seconds";i:1;}'),
            self::INVALID_DATA,
        ];
        yield 'unserialize extra' => [
            static fn() => unserialize(
                'O:13:"Time\Duration":4:{s:7:"seconds";i:1;s:11:"nanoseconds";i:0;s:8:"negative";b:0;s:1:"x";i:1;}',
            ),
            self::INVALID_DATA . ' < Error: Cannot create dynamic property Time\Duration::$x',
        ];
        yield 'unserialize into an existing object' => [
            static fn() => signed(1)->__unserialize(['seconds' => 2, 'nanoseconds' => 0, 'negative' => false]),
            self::INVALID_DATA . ' < Error: Cannot modify readonly property Time\Duration::$seconds',
        ];
        yield 'json' => [
            static fn() => json_encode(signed(1, 2, true), JSON_THROW_ON_ERROR),
            var_export('{"seconds":1,"nanoseconds":2,"negative":true}', true),
        ];
    }
}
