<?php

declare(strict_types=1);

namespace Thesis\Time;

use Time\Duration;
use Time\TimeException;
use function Thesis\Time\Internal\decimal;
use function Thesis\Time\Internal\divide;
use function Thesis\Time\Internal\fromNanoseconds;
use function Thesis\Time\Internal\fromUnits;
use function Thesis\Time\Internal\outOfRange;
use const Thesis\Time\Internal\MAX_SECONDS;
use const Thesis\Time\Internal\MICROSECONDS_PER_SECOND;
use const Thesis\Time\Internal\NANOSECONDS_PER_HOUR;
use const Thesis\Time\Internal\NANOSECONDS_PER_MICROSECOND;
use const Thesis\Time\Internal\NANOSECONDS_PER_MILLISECOND;
use const Thesis\Time\Internal\NANOSECONDS_PER_MINUTE;
use const Thesis\Time\Internal\NANOSECONDS_PER_SECOND;
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
    return fromNanoseconds($nanoseconds);
}

/**
 * Microseconds. A float is rounded to the nearest nanosecond, half away from zero.
 *
 * @api
 * @throws TimeException out of the range of Duration
 */
function us(int|float $microseconds): Duration
{
    return fromUnits($microseconds, NANOSECONDS_PER_MICROSECOND);
}

/**
 * A float is rounded to the nearest nanosecond, half away from zero.
 *
 * @api
 * @throws TimeException out of the range of Duration
 */
function ms(int|float $milliseconds): Duration
{
    return fromUnits($milliseconds, NANOSECONDS_PER_MILLISECOND);
}

/**
 * A float is rounded to the nearest nanosecond, half away from zero.
 *
 * @api
 * @throws TimeException out of the range of Duration
 */
function sec(int|float $seconds): Duration
{
    return fromUnits($seconds, NANOSECONDS_PER_SECOND);
}

/**
 * A float is rounded to the nearest nanosecond, half away from zero.
 *
 * @api
 * @throws TimeException out of the range of Duration
 */
function minutes(int|float $minutes): Duration
{
    return fromUnits($minutes, NANOSECONDS_PER_MINUTE);
}

/**
 * A float is rounded to the nearest nanosecond, half away from zero.
 *
 * @api
 * @throws TimeException out of the range of Duration
 */
function hours(int|float $hours): Duration
{
    return fromUnits($hours, NANOSECONDS_PER_HOUR);
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
 * Exact: every Duration fits in int nanoseconds.
 *
 * @api
 */
function toNs(Duration $duration): int
{
    $nanoseconds = $duration->seconds * NANOSECONDS_PER_SECOND + $duration->nanoseconds;

    return $duration->negative ? -$nanoseconds : $nanoseconds;
}

/**
 * Whole microseconds.
 *
 * @api
 */
function toUs(Duration $duration, \RoundingMode $rounding = \RoundingMode::TowardsZero): int
{
    return divide(toNs($duration), NANOSECONDS_PER_MICROSECOND, $rounding);
}

/**
 * Whole milliseconds.
 *
 * @api
 */
function toMs(Duration $duration, \RoundingMode $rounding = \RoundingMode::TowardsZero): int
{
    return divide(toNs($duration), NANOSECONDS_PER_MILLISECOND, $rounding);
}

/**
 * Whole seconds, see toSecFloat() for a fraction.
 *
 * @api
 */
function toSec(Duration $duration, \RoundingMode $rounding = \RoundingMode::TowardsZero): int
{
    return divide(toNs($duration), NANOSECONDS_PER_SECOND, $rounding);
}

/**
 * Whole minutes.
 *
 * @api
 */
function toMinutes(Duration $duration, \RoundingMode $rounding = \RoundingMode::TowardsZero): int
{
    return divide(toNs($duration), NANOSECONDS_PER_MINUTE, $rounding);
}

/**
 * Whole hours.
 *
 * @api
 */
function toHours(Duration $duration, \RoundingMode $rounding = \RoundingMode::TowardsZero): int
{
    return divide(toNs($duration), NANOSECONDS_PER_HOUR, $rounding);
}

/**
 * For APIs that take float seconds, like the timers of Revolt.
 *
 * @api
 */
function toSecFloat(Duration $duration): float
{
    $seconds = $duration->seconds + $duration->nanoseconds / NANOSECONDS_PER_SECOND;

    return $duration->negative ? -$seconds : $seconds;
}

/**
 * @api
 */
function isZero(Duration $duration): bool
{
    return $duration->seconds === 0 && $duration->nanoseconds === 0;
}

/**
 * @api
 */
function isNegative(Duration $duration): bool
{
    return $duration->negative;
}

/**
 * @api
 */
function isPositive(Duration $duration): bool
{
    return !$duration->negative && !isZero($duration);
}

/**
 * @api
 */
function shortest(Duration $duration, Duration ...$durations): Duration
{
    foreach ($durations as $another) {
        if (Duration::compare($another, $duration) < 0) {
            $duration = $another;
        }
    }

    return $duration;
}

/**
 * @api
 */
function longest(Duration $duration, Duration ...$durations): Duration
{
    foreach ($durations as $another) {
        if (Duration::compare($another, $duration) > 0) {
            $duration = $another;
        }
    }

    return $duration;
}

/**
 * Unlike Duration::multiplyBy(), takes a negative factor and a float one. An int factor is exact,
 * a float one is applied to the nanoseconds as a float: rounded to the nearest nanosecond, half away from zero,
 * and exact to the nanosecond while the product is below 2^53 nanoseconds (about 104 days).
 *
 * @api
 * @throws TimeException out of the range of Duration
 */
function multiply(Duration $duration, int|float $factor): Duration
{
    if (\is_float($factor) || $factor === PHP_INT_MIN) {
        return fromUnits(toNs($duration) * $factor, 1);
    }

    return $factor >= 0 ? $duration->multiplyBy($factor) : $duration->multiplyBy(-$factor)->negate();
}

/**
 * Like Go's Duration.String(): 1h2m3.5s, 1m0s, 1.5s, 2.5ms, 1µs, 15ns, 0s, -1.5s.
 *
 * @api
 */
function format(Duration $duration): string
{
    $sign = $duration->negative ? '-' : '';

    if ($duration->seconds === 0) {
        $nanoseconds = $duration->nanoseconds;

        return $sign . match (true) {
            $nanoseconds === 0 => '0s',
            $nanoseconds < NANOSECONDS_PER_MICROSECOND => $nanoseconds . 'ns',
            $nanoseconds < NANOSECONDS_PER_MILLISECOND => decimal($nanoseconds, NANOSECONDS_PER_MICROSECOND) . 'µs',
            default => decimal($nanoseconds, NANOSECONDS_PER_MILLISECOND) . 'ms',
        };
    }

    $hours = intdiv($duration->seconds, SECONDS_PER_HOUR);
    $minutes = intdiv($duration->seconds % SECONDS_PER_HOUR, SECONDS_PER_MINUTE);
    $nanoseconds = $duration->seconds % SECONDS_PER_MINUTE * NANOSECONDS_PER_SECOND + $duration->nanoseconds;
    $seconds = decimal($nanoseconds, NANOSECONDS_PER_SECOND);

    return $sign . match (true) {
        $hours > 0 => "{$hours}h{$minutes}m{$seconds}s",
        $minutes > 0 => "{$minutes}m{$seconds}s",
        default => "{$seconds}s",
    };
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
    $seconds = $to->getTimestamp() - $from->getTimestamp();

    // Far beyond the range of Duration, and the multiplication below would overflow.
    if ($seconds > MAX_SECONDS + 1 || $seconds < -(MAX_SECONDS + 1)) {
        throw outOfRange();
    }

    // In microseconds, a second over the range can still come back into it, and nothing overflows.
    $microseconds = $seconds * MICROSECONDS_PER_SECOND + (int) $to->format('u') - (int) $from->format('u');

    $maxMicroseconds = MAX_SECONDS * MICROSECONDS_PER_SECOND + MICROSECONDS_PER_SECOND - 1;

    if ($microseconds > $maxMicroseconds || $microseconds < -$maxMicroseconds) {
        throw outOfRange();
    }

    return fromNanoseconds($microseconds * NANOSECONDS_PER_MICROSECOND);
}

/**
 * $time moved by the duration: later, or earlier if the duration is negative. The duration is elapsed time,
 * whatever the UTC offset (DST) does in between. DateTimeImmutable keeps microseconds:
 * the rest of the nanoseconds is dropped.
 *
 * @api
 */
function shift(\DateTimeImmutable $time, Duration $duration): \DateTimeImmutable
{
    $microseconds = $time->getTimestamp() * MICROSECONDS_PER_SECOND + $time->getMicrosecond() + toUs($duration);
    // A timestamp is floored, and the microseconds are counted up from it, before 1970 too.
    $seconds = divide($microseconds, MICROSECONDS_PER_SECOND, \RoundingMode::NegativeInfinity);
    $fraction = $microseconds - $seconds * MICROSECONDS_PER_SECOND;
    \assert($fraction >= 0 && $fraction < MICROSECONDS_PER_SECOND);

    return $time->setTimestamp($seconds)->setMicrosecond($fraction);
}
