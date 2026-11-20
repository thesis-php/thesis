<?php

declare(strict_types=1);

namespace Thesis\Time;

use Time\Duration;
use Time\TimeException;
use function Thesis\Time\Internal\decimal;
use function Thesis\Time\Internal\fromFloat;
use function Thesis\Time\Internal\fromFractions;
use function Thesis\Time\Internal\fromMultiples;
use function Thesis\Time\Internal\fromParts;
use function Thesis\Time\Internal\outOfRange;
use function Thesis\Time\Internal\rounded;
use function Thesis\Time\Internal\roundingStep;
use function Thesis\Time\Internal\timestamp;
use function Thesis\Time\Internal\toFloatFractions;
use function Thesis\Time\Internal\toFloatMultiples;
use function Thesis\Time\Internal\toFractions;
use function Thesis\Time\Internal\toMultiples;
use function Thesis\Time\Internal\whole;
use const Thesis\Time\Internal\MICROSECONDS_PER_SECOND;
use const Thesis\Time\Internal\MILLISECONDS_PER_SECOND;
use const Thesis\Time\Internal\NANOSECONDS_PER_MICROSECOND;
use const Thesis\Time\Internal\NANOSECONDS_PER_MILLISECOND;
use const Thesis\Time\Internal\NANOSECONDS_PER_SECOND;
use const Thesis\Time\Internal\NUMBER_WITH_UNIT;
use const Thesis\Time\Internal\SECONDS_PER_HOUR;
use const Thesis\Time\Internal\SECONDS_PER_MINUTE;

/**
 * Unlike Duration::fromNanoseconds(), takes a negative value too.
 *
 * @api
 * @throws TimeException out of the range of Duration
 */
function ns(int $nanoseconds): Duration
{
    return fromFractions($nanoseconds, NANOSECONDS_PER_SECOND);
}

/**
 * Microseconds. A float is rounded to the nearest nanosecond, half away from zero.
 *
 * @api
 * @throws TimeException out of the range of Duration
 */
function us(int|float $microseconds): Duration
{
    return fromFractions($microseconds, MICROSECONDS_PER_SECOND);
}

/**
 * A float is rounded to the nearest nanosecond, half away from zero.
 *
 * @api
 * @throws TimeException out of the range of Duration
 */
function ms(int|float $milliseconds): Duration
{
    return fromFractions($milliseconds, MILLISECONDS_PER_SECOND);
}

/**
 * A float is rounded to the nearest nanosecond, half away from zero.
 *
 * @api
 * @throws TimeException out of the range of Duration
 */
function sec(int|float $seconds): Duration
{
    return fromMultiples($seconds, 1);
}

/**
 * A float is rounded to the nearest nanosecond, half away from zero.
 *
 * @api
 * @throws TimeException out of the range of Duration
 */
function minutes(int|float $minutes): Duration
{
    return fromMultiples($minutes, SECONDS_PER_MINUTE);
}

/**
 * A float is rounded to the nearest nanosecond, half away from zero.
 *
 * @api
 * @throws TimeException out of the range of Duration
 */
function hours(int|float $hours): Duration
{
    return fromMultiples($hours, SECONDS_PER_HOUR);
}

/**
 * The sum of the parts, each converted like the function of its unit: duration(minutes: 1, sec: 30).
 *
 * @api
 * @throws TimeException out of the range of Duration
 */
function duration(
    int|float $hours = 0,
    int|float $minutes = 0,
    int|float $sec = 0,
    int|float $ms = 0,
    int|float $us = 0,
    int $ns = 0,
): Duration {
    return hours($hours)
        ->add(minutes($minutes))
        ->add(sec($sec))
        ->add(ms($ms))
        ->add(us($us))
        ->add(ns($ns))
    ;
}

/**
 * Exact: on 64-bit PHP every Duration fits in int nanoseconds.
 *
 * @api
 * @throws TimeException on 32-bit PHP, above PHP_INT_MAX nanoseconds (2.1 seconds)
 */
function toNs(Duration $duration): int
{
    return rounded(
        quotient: $duration->seconds * NANOSECONDS_PER_SECOND + $duration->nanoseconds,
        half: null,
        negative: $duration->negative,
        rounding: \RoundingMode::TowardsZero,
    );
}

/**
 * Whole microseconds.
 *
 * @api
 * @throws TimeException on 32-bit PHP, above PHP_INT_MAX microseconds (35 minutes)
 */
function toUs(Duration $duration, \RoundingMode $rounding): int
{
    return toFractions($duration, MICROSECONDS_PER_SECOND, $rounding);
}

/**
 * Whole milliseconds.
 *
 * @api
 * @throws TimeException on 32-bit PHP, above PHP_INT_MAX milliseconds (24 days)
 */
function toMs(Duration $duration, \RoundingMode $rounding): int
{
    return toFractions($duration, MILLISECONDS_PER_SECOND, $rounding);
}

/**
 * Whole seconds, see toSecFloat() for a fraction.
 *
 * @api
 * @throws TimeException on 32-bit PHP, if the longest duration is rounded away from zero
 */
function toSec(Duration $duration, \RoundingMode $rounding): int
{
    return toMultiples($duration, 1, $rounding);
}

/**
 * Whole minutes.
 *
 * @api
 */
function toMinutes(Duration $duration, \RoundingMode $rounding): int
{
    return toMultiples($duration, SECONDS_PER_MINUTE, $rounding);
}

/**
 * Whole hours.
 *
 * @api
 */
function toHours(Duration $duration, \RoundingMode $rounding): int
{
    return toMultiples($duration, SECONDS_PER_HOUR, $rounding);
}

/**
 * Nanoseconds as a float: exact up to 2^53 (104 days), and never throws, unlike toNs() on 32-bit PHP.
 *
 * @api
 */
function toNsFloat(Duration $duration): float
{
    return toFloatFractions($duration, NANOSECONDS_PER_SECOND);
}

/**
 * Microseconds with a fraction.
 *
 * @api
 */
function toUsFloat(Duration $duration): float
{
    return toFloatFractions($duration, MICROSECONDS_PER_SECOND);
}

/**
 * Milliseconds with a fraction.
 *
 * @api
 */
function toMsFloat(Duration $duration): float
{
    return toFloatFractions($duration, MILLISECONDS_PER_SECOND);
}

/**
 * Seconds with a fraction, for APIs that take float seconds, like the timeout of `stream_socket_client()`.
 *
 * @api
 */
function toSecFloat(Duration $duration): float
{
    return toFloatMultiples($duration, 1);
}

/**
 * Minutes with a fraction.
 *
 * @api
 */
function toMinutesFloat(Duration $duration): float
{
    return toFloatMultiples($duration, SECONDS_PER_MINUTE);
}

/**
 * Hours with a fraction.
 *
 * @api
 */
function toHoursFloat(Duration $duration): float
{
    return toFloatMultiples($duration, SECONDS_PER_HOUR);
}

/**
 * @api
 */
function isZero(Duration $duration): bool
{
    return $duration->seconds === 0 && $duration->nanoseconds === 0;
}

/**
 * Unlike Duration::multiplyBy(), takes a negative and a float factor. An int factor is exact, a float one
 * is rounded to the nearest nanosecond, half away from zero, exact while both durations are below 2^53 ns (104 days).
 *
 * @api
 * @throws TimeException out of the range of Duration
 */
function multiply(Duration $duration, int|float $factor): Duration
{
    if (\is_float($factor) || $factor === PHP_INT_MIN) {
        // Exact on 64-bit PHP, on 32-bit PHP a float, exact as well: the product is a multiple of 2^9 below 2^61.
        $nanoseconds = $duration->seconds * NANOSECONDS_PER_SECOND + $duration->nanoseconds;

        return fromFloat(($duration->negative ? -$nanoseconds : $nanoseconds) * $factor, 1);
    }

    return $factor >= 0 ? $duration->multiplyBy($factor) : $duration->multiplyBy(-$factor)->negate();
}

/**
 * How many times $divisor fits in $dividend, with a fraction: a progress, a rate, the intervals that have passed.
 * The nearest float while both durations are below 2^53 ns (104 days), a few units in the last place off above.
 *
 * @api
 * @throws \DivisionByZeroError if $divisor is zero
 */
function ratio(Duration $dividend, Duration $divisor): float
{
    // The nanoseconds, not the float seconds, which are rounded already: 0.3 / 0.1 is 2.9999999999999996.
    return toNsFloat($dividend) / toNsFloat($divisor);
}

/**
 * The duration rounded to a multiple of the unit, like Go's Duration.Round() and Duration.Truncate():
 * roundDurationTo($elapsed, ms(1), RoundingMode::HalfAwayFromZero) makes 1.234567891s 1.235s.
 *
 * @api
 * @param Duration $unit positive, of any length: 1ms, 1.5s, 5m
 * @throws \ValueError if the unit is not positive
 * @throws TimeException out of the range of Duration
 */
function roundDurationTo(Duration $duration, Duration $unit, \RoundingMode $rounding): Duration
{
    $step = roundingStep($duration->seconds, $duration->nanoseconds, $duration->negative, $unit, $rounding);
    $magnitude = $duration->absolute()->add($step);

    return $duration->negative ? $magnitude->negate() : $magnitude;
}

/**
 * Like Go's Duration.String(): 1h2m3.5s, 1m0s, 1.5s, 2.5ms, 1µs, 15ns, 0s, -1.5s.
 *
 * @api
 */
function formatDuration(Duration $duration): string
{
    $sign = $duration->negative ? '-' : '';

    if ($duration->seconds === 0) {
        $nanoseconds = $duration->nanoseconds;

        return $sign . match (true) {
            $nanoseconds === 0 => '0s',
            $nanoseconds < NANOSECONDS_PER_MICROSECOND => $nanoseconds . 'ns',
            $nanoseconds < NANOSECONDS_PER_MILLISECOND => decimal(
                whole: intdiv($nanoseconds, NANOSECONDS_PER_MICROSECOND),
                fraction: $nanoseconds % NANOSECONDS_PER_MICROSECOND,
                unit: NANOSECONDS_PER_MICROSECOND,
            ) . 'µs',
            default => decimal(
                whole: intdiv($nanoseconds, NANOSECONDS_PER_MILLISECOND),
                fraction: $nanoseconds % NANOSECONDS_PER_MILLISECOND,
                unit: NANOSECONDS_PER_MILLISECOND,
            ) . 'ms',
        };
    }

    $hours = intdiv($duration->seconds, SECONDS_PER_HOUR);
    $minutes = intdiv($duration->seconds % SECONDS_PER_HOUR, SECONDS_PER_MINUTE);
    $seconds = decimal($duration->seconds % SECONDS_PER_MINUTE, $duration->nanoseconds, NANOSECONDS_PER_SECOND);

    return $sign . match (true) {
        $hours > 0 => "{$hours}h{$minutes}m{$seconds}s",
        $minutes > 0 => "{$minutes}m{$seconds}s",
        default => "{$seconds}s",
    };
}

/**
 * The reverse of formatDuration(), like Go's ParseDuration(): 1h30m, 1.5s, 250ms, -15ns, 0. Numbers with a unit each
 * (h, m, s, ms, us or µs, ns) after an optional sign, a fraction in any of them, nothing in between.
 * A number is converted like the function of its unit: parseDuration('1.5h') is hours(1.5).
 *
 * @api
 * @throws TimeException if the string is not such a duration or is out of the range of Duration
 */
function parseDuration(string $string): Duration
{
    $negative = str_starts_with($string, '-');
    $offset = $negative || str_starts_with($string, '+') ? 1 : 0;
    $duration = Duration::fromSeconds(0);

    // Only zero has no unit.
    if (substr($string, $offset) === '0') {
        return $duration;
    }

    $length = \strlen($string);

    do {
        if (preg_match(NUMBER_WITH_UNIT, $string, $match, offset: $offset) !== 1) {
            throw new TimeException(\sprintf(
                'A duration must be like 1h30m, 1.5s or 250ms, %s given',
                var_export($string, return: true),
            ));
        }

        [$numberWithUnit, $number, $unit] = $match;
        $value = (float) $number;

        // More than 308 digits. fromFloat() takes INF for a wrong argument, not for a duration out of the range.
        if (is_infinite($value)) {
            throw outOfRange();
        }

        $duration = $duration->add(fromFloat($value, match ($unit) {
            'ns' => 1,
            'us', "\u{B5}s", "\u{3BC}s" => NANOSECONDS_PER_MICROSECOND,
            'ms' => NANOSECONDS_PER_MILLISECOND,
            's' => NANOSECONDS_PER_SECOND,
            'm' => SECONDS_PER_MINUTE * NANOSECONDS_PER_SECOND,
            'h' => SECONDS_PER_HOUR * NANOSECONDS_PER_SECOND,
        }));
        $offset += \strlen($numberWithUnit);
    } while ($offset < $length);

    return $negative ? $duration->negate() : $duration;
}

/**
 * The elapsed time from $from to $to, negative if $to is earlier. Computed from the timestamps,
 * so a change of the UTC offset (DST) between them does not count.
 *
 * @api
 * @throws TimeException out of the range of Duration
 */
function between(\DateTimeInterface $from, \DateTimeInterface $to): Duration
{
    // A float on 32-bit PHP beyond int, in the range or not: fromParts() takes either.
    $seconds = timestamp($to) - timestamp($from);
    $microseconds = (int) $to->format('u') - (int) $from->format('u');

    // The parts take the sign of the whole: -1.25 seconds are -1 second and -250_000 microseconds.
    if ($seconds > 0 && $microseconds < 0) {
        --$seconds;
        $microseconds += MICROSECONDS_PER_SECOND;
    } elseif ($seconds < 0 && $microseconds > 0) {
        ++$seconds;
        $microseconds -= MICROSECONDS_PER_SECOND;
    }

    return fromParts($seconds, $microseconds * NANOSECONDS_PER_MICROSECOND);
}

/**
 * $time moved by the duration, back if it is negative, whatever the UTC offset (DST) does in between.
 * The nanoseconds below a microsecond are dropped: DateTimeImmutable cannot hold them.
 *
 * @api
 */
function shift(\DateTimeImmutable $time, Duration $duration): \DateTimeImmutable
{
    $sign = $duration->negative ? -1 : 1;
    // A float on 32-bit PHP beyond int: DateTimeImmutable takes timestamps of int64.
    $seconds = timestamp($time) + $sign * $duration->seconds;
    $microseconds = (int) $time->format('u') + $sign * intdiv($duration->nanoseconds, NANOSECONDS_PER_MICROSECOND);

    if ($microseconds < 0) {
        --$seconds;
        $microseconds += MICROSECONDS_PER_SECOND;
    } elseif ($microseconds >= MICROSECONDS_PER_SECOND) {
        ++$seconds;
        $microseconds -= MICROSECONDS_PER_SECOND;
    }

    // Not setMicrosecond(), which is of PHP 8.4. modify() keeps the class of $time, but '@' sets UTC,
    // and the sign is of the whole number: '@-0.250000' is a quarter of a second before 1970.
    if ($seconds < 0 && $microseconds > 0) {
        $timestamp = \sprintf('@-%s.%06d', whole(-($seconds + 1)), MICROSECONDS_PER_SECOND - $microseconds);
    } else {
        $timestamp = \sprintf('@%s.%06d', whole($seconds), $microseconds);
    }

    return $time->modify($timestamp)->setTimezone($time->getTimezone());
}

/**
 * $time rounded to a multiple of the unit since 1970-01-01 00:00:00 UTC, like Go's Time.Round() and Time.Truncate():
 * roundTimeTo($now, minutes(5), RoundingMode::NegativeInfinity) is the start of a window of 5 minutes.
 * The multiples are of elapsed time, whatever the time zone of $time: those of hours(24) are the midnights of UTC.
 *
 * @api
 * @param Duration $unit positive, of whole microseconds: DateTimeImmutable cannot hold less
 * @throws \ValueError if the unit is not positive or has a fraction of a microsecond
 */
function roundTimeTo(\DateTimeImmutable $time, Duration $unit, \RoundingMode $rounding): \DateTimeImmutable
{
    if ($unit->nanoseconds % NANOSECONDS_PER_MICROSECOND !== 0) {
        throw new \ValueError(\sprintf(
            'A unit of rounding a time must be whole microseconds, %s given',
            formatDuration($unit),
        ));
    }

    // A float on 32-bit PHP beyond int.
    $seconds = timestamp($time);
    $microseconds = (int) $time->format('u');
    $negative = $seconds < 0;

    // The magnitude: a quarter of a second before 1970 is the second -1 and 750_000 microseconds.
    if ($negative && $microseconds > 0) {
        $seconds = -($seconds + 1);
        $microseconds = MICROSECONDS_PER_SECOND - $microseconds;
    } elseif ($negative) {
        $seconds = -$seconds;
    }

    $step = roundingStep($seconds, $microseconds * NANOSECONDS_PER_MICROSECOND, $negative, $unit, $rounding);

    return shift($time, $negative ? $step->negate() : $step);
}
