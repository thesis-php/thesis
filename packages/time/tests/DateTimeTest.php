<?php

declare(strict_types=1);

namespace Thesis\Time;

use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Test;
use Time\Duration;

#[Test]
final class DateTimeTest
{
    private const OUT_OF_RANGE = \PHP_INT_SIZE === 4
        ? 'Time\TimeException: The maximum representable range is 2_147_483_647 seconds (roughly 68 years)'
        : 'Time\TimeException: The maximum representable range is 9_223_372_035 seconds (roughly 292 years)';

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function intervals(): iterable
    {
        yield 'zero' => ['2026-01-01 00:00:00 UTC', '2026-01-01 00:00:00 UTC', '0.000000000'];
        yield 'microseconds' => ['2026-01-01 00:00:00.25 UTC', '2026-01-01 00:00:01.5 UTC', '1.250000000'];
        yield 'backwards' => ['2026-01-01 00:00:01.5 UTC', '2026-01-01 00:00:00.25 UTC', '-1.250000000'];
        yield 'across zones' => ['2026-01-01 03:00:00 +03:00', '2026-01-01 00:00:00 UTC', '0.000000000'];
        // The clocks of Berlin jump from 02:00 to 03:00 on 2026-03-29: an hour of the wall clock is not there.
        yield 'across DST' => [
            '2026-03-29 01:30:00 Europe/Berlin',
            '2026-03-29 03:30:00 Europe/Berlin',
            '3600.000000000',
        ];
        yield 'before 1970' => ['1969-12-31 23:59:59.75 UTC', '1970-01-01 00:00:00.25 UTC', '0.500000000'];
        // Beyond int timestamps on 32-bit PHP.
        yield 'after 2038' => ['2038-01-19 03:14:08 UTC', '2040-01-01 00:00:00 UTC', '61505152.000000000'];
        yield 'before 1901' => ['1901-12-13 20:45:51.5 UTC', '1901-12-13 20:45:52 UTC', '0.500000000'];

        yield from \PHP_INT_SIZE === 4 ? self::intervalsOf32Bits() : self::intervalsOf64Bits();
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function intervalsOf64Bits(): iterable
    {
        yield 'max' => ['1970-01-01 00:00:00 UTC', '2262-04-11 23:47:15.999999 UTC', '9223372035.999999000'];
        yield 'above max' => ['1970-01-01 00:00:00 UTC', '2262-04-11 23:47:16 UTC', self::OUT_OF_RANGE];
        yield 'below min' => ['2262-04-11 23:47:16 UTC', '1970-01-01 00:00:00 UTC', self::OUT_OF_RANGE];
        yield 'back into range by microseconds' => [
            '1970-01-01 00:00:00.5 UTC',
            '2262-04-11 23:47:16.25 UTC',
            '9223372035.750000000',
        ];
        yield 'far beyond range' => ['@-3000000000000', '@3000000000000', self::OUT_OF_RANGE];
    }

    /**
     * A timestamp is an int: the range of Duration is from 1970 to 2038, the difference of two timestamps
     * may overflow.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function intervalsOf32Bits(): iterable
    {
        yield 'max' => ['1970-01-01 00:00:00 UTC', '2038-01-19 03:14:07.999999 UTC', '2147483647.999999000'];
        yield 'above max' => ['1969-12-31 23:59:59 UTC', '2038-01-19 03:14:07 UTC', self::OUT_OF_RANGE];
        yield 'below min' => ['2038-01-19 03:14:07 UTC', '1969-12-31 23:59:59 UTC', self::OUT_OF_RANGE];
        yield 'back into range by microseconds' => [
            '1969-12-31 23:59:59.75 UTC',
            '2038-01-19 03:14:07.5 UTC',
            '2147483647.750000000',
        ];
        yield 'far beyond range' => ['1901-12-13 20:45:52 UTC', '2038-01-19 03:14:07 UTC', self::OUT_OF_RANGE];
    }

    #[DataProvider('intervals')]
    public function measuresBetween(string $from, string $to, string $expected): void
    {
        Assert::same(
            actual: outcome(static fn() => between(new \DateTimeImmutable($from), new \DateTimeImmutable($to))),
            expected: $expected,
        );
    }

    /**
     * @return iterable<string, array{string, Duration, string}>
     */
    public static function shifts(): iterable
    {
        yield 'seconds' => ['2026-01-01 00:00:00 UTC', sec(90), '2026-01-01T00:01:30.000000+00:00'];
        yield 'microseconds with a carry' => [
            '2026-01-01 00:00:00.75 UTC',
            ms(500),
            '2026-01-01T00:00:01.250000+00:00',
        ];
        yield 'negative' => ['2026-01-01 00:00:00.25 UTC', ms(-500), '2025-12-31T23:59:59.750000+00:00'];
        yield 'nanoseconds are dropped' => ['2026-01-01 00:00:00 UTC', ns(1999), '2026-01-01T00:00:00.000001+00:00'];
        yield 'negative nanoseconds are dropped' => [
            '2026-01-01 00:00:00 UTC',
            ns(-1999),
            '2025-12-31T23:59:59.999999+00:00',
        ];
        yield 'across DST' => [
            '2026-03-29 01:30:00 Europe/Berlin',
            hours(1),
            '2026-03-29T03:30:00.000000+02:00',
        ];
        yield 'keeps the time zone' => [
            '2026-01-01 00:00:00 Europe/Moscow',
            hours(1),
            '2026-01-01T01:00:00.000000+03:00',
        ];
        yield 'before 1970' => ['1970-01-01 00:00:00.25 UTC', ms(-500), '1969-12-31T23:59:59.750000+00:00'];
        yield 'before 1970 by whole seconds' => [
            '1970-01-01 00:00:00 UTC',
            sec(-2),
            '1969-12-31T23:59:58.000000+00:00',
        ];
        // Beyond int timestamps on 32-bit PHP.
        yield 'after 2038' => ['2038-01-19 03:14:07.75 UTC', ms(500), '2038-01-19T03:14:08.250000+00:00'];
        yield 'before 1901' => ['1901-12-13 20:45:52 UTC', ms(-1500), '1901-12-13T20:45:50.500000+00:00'];
        yield 'from after 2038' => ['2038-11-15 12:00:00 UTC', sec(1), '2038-11-15T12:00:01.000000+00:00'];
        yield 'from before 1901' => ['1901-01-01 00:00:00.5 UTC', ms(-1000), '1900-12-31T23:59:59.500000+00:00'];
    }

    #[DataProvider('shifts')]
    public function shiftsByElapsedTime(string $time, Duration $duration, string $expected): void
    {
        Assert::same(shift(new \DateTimeImmutable($time), $duration)->format('Y-m-d\TH:i:s.uP'), $expected);
    }

    public function measuresWhatItShifted(): void
    {
        $time = new \DateTimeImmutable('2026-03-29 01:30:00.123456 Europe/Berlin');
        $duration = duration(hours: 49, sec: 1.5, us: 7);

        Assert::same(outcome(static fn() => between($time, shift($time, $duration))), describe($duration));
    }
}
