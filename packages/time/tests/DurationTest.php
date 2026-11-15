<?php

declare(strict_types=1);

namespace Thesis\Time;

use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Test;
use Time\Duration;
use Time\TimeException;

/**
 * Pins the behavior of Time\Duration with values taken from the class of PHP 8.6: the same test passes
 * on PHP 8.5 against the polyfill and on PHP 8.6 against the original.
 */
#[Test]
final class DurationTest
{
    private const string OUT_OF_RANGE = 'Time\TimeException: The maximum representable range is 9_223_372_035 seconds (roughly 292 years)';
    private const string PARSE_FAILURE = 'Time\TimeException: The ISO 8601 duration string could not be parsed';
    private const string DAYS_FOUND = 'Time\TimeException: The ISO 8601 duration string may only contain the time (T) aspect';
    private const string ONLY_PERIOD = 'Time\TimeException: The ISO 8601 duration string may only contain the period (P) aspect';
    private const string MISSING_PERIOD = 'Time\TimeException: The ISO 8601 duration string is missing the period (P) aspect';
    private const string INVALID_DATA = 'Exception: Invalid serialization data for Time\Duration object';

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function factories(): iterable
    {
        yield 'seconds' => [static fn() => Duration::fromSeconds(90), '90.000000000'];
        yield 'seconds and nanoseconds' => [static fn() => Duration::fromSeconds(1, 5), '1.000000005'];
        yield 'max' => [static fn() => Duration::fromSeconds(9_223_372_035, 999_999_999), '9223372035.999999999'];
        yield 'seconds above max' => [static fn() => Duration::fromSeconds(9_223_372_036), self::OUT_OF_RANGE];
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
        yield 'max nanoseconds' => [static fn() => Duration::fromNanoseconds(PHP_INT_MAX), self::OUT_OF_RANGE];
        yield 'microseconds' => [
            static fn() => Duration::fromMicroseconds(9_223_372_035_999_999),
            '9223372035.999999000',
        ];
        yield 'microseconds above max' => [
            static fn() => Duration::fromMicroseconds(9_223_372_036_000_000),
            self::OUT_OF_RANGE,
        ];
        yield 'milliseconds' => [static fn() => Duration::fromMilliseconds(1500), '1.500000000'];
        yield 'max milliseconds' => [static fn() => Duration::fromMilliseconds(PHP_INT_MAX), self::OUT_OF_RANGE];
        yield 'minutes' => [static fn() => Duration::fromMinutes(153_722_867), '9223372020.000000000'];
        yield 'minutes above max' => [static fn() => Duration::fromMinutes(153_722_868), self::OUT_OF_RANGE];
        yield 'max minutes' => [static fn() => Duration::fromMinutes(PHP_INT_MAX), self::OUT_OF_RANGE];
        yield 'hours' => [static fn() => Duration::fromHours(2_562_047), '9223369200.000000000'];
        yield 'hours above max' => [static fn() => Duration::fromHours(2_562_048), self::OUT_OF_RANGE];
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
        yield 'add to zero' => [
            static fn() => signed(9_223_372_035, 999_999_999, true)->add(signed(9_223_372_035, 999_999_999)),
            '0.000000000',
        ];
        yield 'add above max' => [
            static fn() => signed(9_223_372_035, 999_999_999)->add(Duration::fromNanoseconds(1)),
            self::OUT_OF_RANGE,
        ];
        yield 'sub to zero' => [static fn() => signed(1)->sub(signed(1)), '0.000000000'];
        yield 'sub below zero' => [static fn() => signed(0, 1)->sub(signed(0, 2)), '-0.000000001'];
        yield 'sub negatives' => [
            static fn() => signed(1, negative: true)->sub(signed(3, negative: true)),
            '2.000000000',
        ];
        yield 'sub below min' => [
            static fn() => signed(9_223_372_035, negative: true)->sub(signed(1)),
            self::OUT_OF_RANGE,
        ];
        yield 'multiply by zero' => [static fn() => signed(1, negative: true)->multiplyBy(0), '0.000000000'];
        yield 'multiply negative' => [static fn() => signed(1, negative: true)->multiplyBy(3), '-3.000000000'];
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
        yield 'multiply by negative' => [
            static fn() => signed(1)->multiplyBy(-1),
            'ValueError: Time\Duration::multiplyBy(): Argument #1 ($factor) must be greater than or equal to 0',
        ];
        yield 'divide truncates' => [static fn() => signed(1)->divideBy(3), '0.333333333'];
        yield 'divide carries the remainder' => [static fn() => signed(5, 5)->divideBy(2), '2.500000002'];
        yield 'divide negative' => [static fn() => signed(1, negative: true)->divideBy(3), '-0.333333333'];
        yield 'divide to zero is not negative' => [static fn() => signed(0, 1, true)->divideBy(2), '0.000000000'];
        yield 'divide max' => [static fn() => signed(9_223_372_035, 999_999_999)->divideBy(7), '1317624576.571428571'];
        yield 'divide max by a larger number' => [
            static fn() => signed(9_223_372_035, 999_999_999)->divideBy(9_223_372_036),
            '0.999999999',
        ];
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
        // Assert::equals(), not ==: php-cs-fixer turns == into ===, and the class of PHP 8.6 caches
        // the last created duration, so === would pass by accident there.
        Assert::equals($one, Duration::fromMilliseconds(1000));
        Assert::notEquals($one, $tiny);
        Assert::true($one >= Duration::fromMilliseconds(1000));
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
            'PT9223372035S' => '9223372035.000000000',
            'PT2562047H2835S' => '9223372035.000000000',
            'PT2562047H2836S' => self::OUT_OF_RANGE,
            'PT153722868M' => self::OUT_OF_RANGE,
            'PT999999999999H' => self::OUT_OF_RANGE,
            ' PT1S' => '1.000000000',
            "PT1S\n" => '1.000000000',
            "PT1S\t " => '1.000000000',
            'PT1S PT2S' => '2.000000000',
            'PT1S/' => '1.000000000',
            '/PT1S' => '1.000000000',
            'P1D' => self::DAYS_FOUND,
            'P1W' => self::DAYS_FOUND,
            'P1Y' => self::DAYS_FOUND,
            'P1M' => self::DAYS_FOUND,
            'P1DT1S' => self::DAYS_FOUND,
            'P0000-00-01T00:00:00' => self::DAYS_FOUND,
            'R5/PT1S' => self::ONLY_PERIOD,
            '2008-01-01T00:00:00Z/PT1S' => self::ONLY_PERIOD,
            '20080101T000000Z/PT1S' => self::ONLY_PERIOD,
            'PT1S/2008-01-01T00:00:00Z' => self::ONLY_PERIOD,
            '2008-01-01T00:00:00Z' => self::MISSING_PERIOD,
            ' ' => self::MISSING_PERIOD,
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
        yield 'unserialize max' => [
            static fn() => unserialize($data('i:9223372035', 'i:999999999', 'b:0')),
            '9223372035.999999999',
        ];
        yield 'unserialize in another order' => [
            static fn() => unserialize('O:13:"Time\Duration":3:{s:11:"nanoseconds";i:2;s:7:"seconds";i:1;s:8:"negative";b:0;}'),
            '1.000000002',
        ];
        yield 'unserialize above max' => [
            static fn() => unserialize($data('i:9223372036', 'i:0', 'b:0')),
            self::INVALID_DATA . ' < Time\TimeException',
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
            self::INVALID_DATA . ' < TypeError',
        ];
        yield 'unserialize int as bool' => [
            static fn() => unserialize($data('i:1', 'i:0', 'i:1')),
            self::INVALID_DATA . ' < TypeError',
        ];
        yield 'unserialize missing' => [
            static fn() => unserialize('O:13:"Time\Duration":1:{s:7:"seconds";i:1;}'),
            self::INVALID_DATA,
        ];
        yield 'unserialize extra' => [
            static fn() => unserialize(
                'O:13:"Time\Duration":4:{s:7:"seconds";i:1;s:11:"nanoseconds";i:0;s:8:"negative";b:0;s:1:"x";i:1;}',
            ),
            self::INVALID_DATA . ' < Error',
        ];
        yield 'unserialize into an existing object' => [
            static fn() => signed(1)->__unserialize(['seconds' => 2, 'nanoseconds' => 0, 'negative' => false]),
            self::INVALID_DATA . ' < Error',
        ];
        yield 'json' => [
            static fn() => json_encode(signed(1, 2, true), JSON_THROW_ON_ERROR),
            var_export('{"seconds":1,"nanoseconds":2,"negative":true}', true),
        ];
    }
}
