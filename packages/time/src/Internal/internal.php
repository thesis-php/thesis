<?php

declare(strict_types=1);

namespace Thesis\Time\Internal;

use Time\Duration;
use Time\TimeException;

/** @internal */
const NANOSECONDS_PER_MICROSECOND = 1000;

/** @internal */
const NANOSECONDS_PER_MILLISECOND = 1000 * NANOSECONDS_PER_MICROSECOND;

/** @internal */
const NANOSECONDS_PER_SECOND = 1000 * NANOSECONDS_PER_MILLISECOND;

/** @internal */
const MICROSECONDS_PER_SECOND = NANOSECONDS_PER_SECOND / NANOSECONDS_PER_MICROSECOND;

/** @internal */
const MILLISECONDS_PER_SECOND = NANOSECONDS_PER_SECOND / NANOSECONDS_PER_MILLISECOND;

/** @internal */
const SECONDS_PER_MINUTE = 60;

/** @internal */
const SECONDS_PER_HOUR = 60 * SECONDS_PER_MINUTE;

/**
 * The longest `Time\Duration` in whole seconds, as the class has it: PHP_INT_MAX nanoseconds less a part of a second,
 * on 32-bit PHP the int $seconds.
 *
 * @internal
 */
const MAX_SECONDS = \PHP_INT_SIZE === 4 ? PHP_INT_MAX : 9_223_372_035;

/**
 * A value in units of 1 / $perSecond of a second: nanoseconds, microseconds, milliseconds.
 *
 * @internal
 * @param positive-int $perSecond a divisor of a billion
 * @throws TimeException out of the range of Duration
 */
function fromFractions(int|float $value, int $perSecond): Duration
{
    $nanosecondsPerUnit = intdiv(NANOSECONDS_PER_SECOND, $perSecond);

    if (\is_float($value)) {
        return fromFloat($value, $nanosecondsPerUnit);
    }

    return fromParts(intdiv($value, $perSecond), $value % $perSecond * $nanosecondsPerUnit);
}

/**
 * A value in units of $secondsPerUnit seconds: seconds, minutes, hours.
 *
 * @internal
 * @param positive-int $secondsPerUnit
 * @throws TimeException out of the range of Duration
 */
function fromMultiples(int|float $value, int $secondsPerUnit): Duration
{
    if (\is_float($value)) {
        // A float on 32-bit PHP, of the same value: the multiplication rounds the same.
        return fromFloat($value, $secondsPerUnit * NANOSECONDS_PER_SECOND);
    }

    return fromParts($value * $secondsPerUnit, 0);
}

/**
 * Rounded to the nearest nanosecond, half away from zero.
 *
 * @internal
 * @throws TimeException out of the range of Duration
 */
function fromFloat(float $value, int|float $nanosecondsPerUnit): Duration
{
    if (!is_finite($value)) {
        // var_export(): PHP 8.5 warns when %s coerces NAN to a string.
        throw new \ValueError(\sprintf('A duration must be finite, %s given', var_export($value, return: true)));
    }

    $nanoseconds = roundHalfAwayFromZero($value * $nanosecondsPerUnit);

    // A finite value times the unit may overflow to INF, and fmod() of INF is NAN.
    if (!is_finite($nanoseconds)) {
        throw outOfRange();
    }

    $magnitude = abs($nanoseconds);
    // fmod() is exact. Above 2^62 nanoseconds a float is a multiple of 1024, an odd number of seconds is not:
    // the subtraction is off by up to 512 nanoseconds, so the quotient is rounded, not truncated.
    $fraction = fmod($magnitude, NANOSECONDS_PER_SECOND);
    $seconds = roundHalfAwayFromZero(($magnitude - $fraction) / NANOSECONDS_PER_SECOND);

    // Before the cast: a float beyond int does not convert.
    if ($seconds > MAX_SECONDS) {
        throw outOfRange();
    }

    $duration = Duration::fromSeconds((int) $seconds, (int) $fraction);

    return $nanoseconds < 0 ? $duration->negate() : $duration;
}

/**
 * Not round(): before PHP 8.4 it rounds to 15 significant digits first, so 0.49999999999999994 becomes 1,
 * and returns a value of 1e15 and above with its fraction, which a cast to int then drops.
 *
 * @internal
 */
function roundHalfAwayFromZero(float $value): float
{
    $magnitude = abs($value);
    $whole = floor($magnitude);
    // Exact: a float less its floor. A fraction exists only below 2^52, where adding 1 is exact too.
    $rounded = $magnitude - $whole >= 0.5 ? $whole + 1 : $whole;

    return $value < 0 ? -$rounded : $rounded;
}

/**
 * @internal
 * @param int|float $seconds a float is an int that overflowed in the arithmetic of the caller: a whole number,
 *     exact on 32-bit PHP, where a step back may return it into the range
 * @param int $nanoseconds of the sign of $seconds, less than a second
 * @throws TimeException out of the range of Duration
 */
function fromParts(int|float $seconds, int $nanoseconds): Duration
{
    $negative = $seconds < 0 || $nanoseconds < 0;
    // -PHP_INT_MIN is a float too.
    $magnitude = $negative ? -$seconds : $seconds;

    // Before the cast: a float beyond int does not convert.
    if ($magnitude > MAX_SECONDS) {
        throw outOfRange();
    }

    $duration = Duration::fromSeconds((int) $magnitude, abs($nanoseconds));

    return $negative ? $duration->negate() : $duration;
}

/**
 * The duration in whole units of 1 / $perSecond of a second: nanoseconds, microseconds, milliseconds.
 *
 * @internal
 * @param positive-int $perSecond a divisor of a billion
 * @throws TimeException if the result does not fit in int, as on 32-bit PHP
 */
function toFractions(Duration $duration, int $perSecond, \RoundingMode $rounding): int
{
    $nanosecondsPerUnit = intdiv(NANOSECONDS_PER_SECOND, $perSecond);
    $remainder = $duration->nanoseconds % $nanosecondsPerUnit;

    return rounded(
        quotient: $duration->seconds * $perSecond + intdiv($duration->nanoseconds, $nanosecondsPerUnit),
        // Less than 2 billion: fits in int on 32-bit PHP too.
        half: $remainder === 0 ? null : $remainder * 2 <=> $nanosecondsPerUnit,
        negative: $duration->negative,
        rounding: $rounding,
    );
}

/**
 * The duration in units of 1 / $perSecond of a second with a fraction: microseconds, milliseconds, seconds.
 *
 * @internal
 * @param positive-int $perSecond a divisor of a billion
 */
function toFloatFractions(Duration $duration, int $perSecond): float
{
    $nanosecondsPerUnit = intdiv(NANOSECONDS_PER_SECOND, $perSecond);

    return toFloatUnits(
        duration: $duration,
        nanosecondsPerUnit: $nanosecondsPerUnit,
        wholeUnits: $duration->seconds * $perSecond + intdiv($duration->nanoseconds, $nanosecondsPerUnit),
        remainderNanoseconds: $duration->nanoseconds % $nanosecondsPerUnit,
    );
}

/**
 * The duration in units of $secondsPerUnit seconds with a fraction: minutes, hours.
 *
 * @internal
 * @param positive-int $secondsPerUnit
 */
function toFloatMultiples(Duration $duration, int $secondsPerUnit): float
{
    return toFloatUnits(
        duration: $duration,
        nanosecondsPerUnit: $secondsPerUnit * NANOSECONDS_PER_SECOND,
        wholeUnits: intdiv($duration->seconds, $secondsPerUnit),
        remainderNanoseconds: $duration->seconds % $secondsPerUnit * NANOSECONDS_PER_SECOND + $duration->nanoseconds,
    );
}

/**
 * The nearest float to the duration in units of $nanosecondsPerUnit, given also as whole units and a remainder.
 * The products wider than int on 32-bit PHP are floats, exact below 2^53.
 *
 * @internal
 */
function toFloatUnits(
    Duration $duration,
    int|float $nanosecondsPerUnit,
    int|float $wholeUnits,
    int|float $remainderNanoseconds,
): float {
    $nanoseconds = $duration->seconds * (float) NANOSECONDS_PER_SECOND + $duration->nanoseconds;

    // Below 2^53 (104 days) a float holds the nanoseconds exactly, and one division rounds to the nearest float.
    // Above, they are rounded, so the whole units stay exact and only the fraction adds a rounding.
    $units = $nanoseconds < 2.0 ** 53
        ? $nanoseconds / $nanosecondsPerUnit
        : $wholeUnits + $remainderNanoseconds / $nanosecondsPerUnit;

    return $duration->negative ? -$units : $units;
}

/**
 * The duration in whole units of $secondsPerUnit seconds: seconds, minutes, hours.
 *
 * @internal
 * @param positive-int $secondsPerUnit
 * @throws TimeException if the result does not fit in int, as on 32-bit PHP
 */
function toMultiples(Duration $duration, int $secondsPerUnit, \RoundingMode $rounding): int
{
    $remainder = [$duration->seconds % $secondsPerUnit, $duration->nanoseconds];
    $half = [intdiv($secondsPerUnit, 2), $secondsPerUnit % 2 * intdiv(NANOSECONDS_PER_SECOND, 2)];

    return rounded(
        quotient: intdiv($duration->seconds, $secondsPerUnit),
        half: $remainder === [0, 0] ? null : $remainder <=> $half,
        negative: $duration->negative,
        rounding: $rounding,
    );
}

/**
 * The seconds of a Unix timestamp. Not getTimestamp(): on 32-bit PHP it throws outside of 1901 to 2038.
 *
 * @internal
 * @return int|float a float beyond int, on 32-bit PHP: exact below 2^53
 */
function timestamp(\DateTimeInterface $time): int|float
{
    $seconds = $time->format('U');
    // Mutation testing runs on 64-bit PHP, where every timestamp fits in int: the 32-bit tests cover it.
    // @infection-ignore CastFloat
    $float = (float) $seconds;

    // @infection-ignore GreaterThan, LessThan, LogicalOr
    return $float > PHP_INT_MAX || $float < PHP_INT_MIN ? $float : (int) $seconds;
}

/**
 * A whole number for a string: %s of a float has the 14 digits of the precision setting.
 *
 * @internal
 */
function whole(int|float $number): string
{
    return \is_float($number) ? \sprintf('%.0f', $number) : (string) $number;
}

/**
 * A quotient truncated toward zero, rounded by how its remainder compares with a half, then signed.
 *
 * @internal
 * @param int|float $quotient a float is an int that overflowed in the arithmetic of the caller
 * @param ?int $half how the remainder compares with a half of the divisor, null if nothing remains
 * @throws TimeException if the result does not fit in int
 */
function rounded(int|float $quotient, ?int $half, bool $negative, \RoundingMode $rounding): int
{
    if (\is_float($quotient)) {
        throw notInt();
    }

    $awayFromZero = $half !== null && match ($rounding) {
        \RoundingMode::TowardsZero => false,
        \RoundingMode::AwayFromZero => true,
        \RoundingMode::PositiveInfinity => !$negative,
        \RoundingMode::NegativeInfinity => $negative,
        \RoundingMode::HalfAwayFromZero => $half >= 0,
        \RoundingMode::HalfTowardsZero => $half > 0,
        \RoundingMode::HalfEven => $half > 0 || ($half === 0 && $quotient % 2 !== 0),
        \RoundingMode::HalfOdd => $half > 0 || ($half === 0 && $quotient % 2 === 0),
    };

    if ($awayFromZero && $quotient === PHP_INT_MAX) {
        throw notInt();
    }

    $magnitude = $awayFromZero ? $quotient + 1 : $quotient;

    return $negative ? -$magnitude : $magnitude;
}

/**
 * The same exception as Time\Duration throws, so a caller handles one kind.
 *
 * @internal
 */
function outOfRange(): TimeException
{
    return new TimeException(\PHP_INT_SIZE === 4
        ? 'The maximum representable range is 2_147_483_647 seconds (roughly 68 years)'
        : 'The maximum representable range is 9_223_372_035 seconds (roughly 292 years)');
}

/**
 * Only on 32-bit PHP: on 64-bit PHP every duration fits in int nanoseconds.
 *
 * @internal
 */
function notInt(): TimeException
{
    return new TimeException('The duration in this unit does not fit in int');
}

/**
 * @internal
 * @param non-negative-int $whole
 * @param non-negative-int $fraction less than $unit
 * @param positive-int $unit a power of 10
 */
function decimal(int $whole, int $fraction, int $unit): string
{
    if ($fraction === 0) {
        return (string) $whole;
    }

    $digits = \strlen((string) $unit) - 1;

    return $whole . '.' . rtrim(str_pad((string) $fraction, $digits, '0', STR_PAD_LEFT), '0');
}
