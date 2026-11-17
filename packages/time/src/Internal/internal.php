<?php

declare(strict_types=1);

namespace Thesis\Time\Internal;

use Time\Duration;
use Time\TimeException;

const NANOSECONDS_PER_MICROSECOND = 1000;
const NANOSECONDS_PER_MILLISECOND = 1_000_000;
const NANOSECONDS_PER_SECOND = 1_000_000_000;
const NANOSECONDS_PER_MINUTE = 60_000_000_000;
const NANOSECONDS_PER_HOUR = 3_600_000_000_000;

/**
 * Every Duration fits in int nanoseconds: its range ends at 9_223_372_035.999999999 seconds, below PHP_INT_MAX.
 *
 * @internal
 * @param positive-int $nanosecondsPerUnit
 */
function fromUnits(int|float $value, int $nanosecondsPerUnit): Duration
{
    if (\is_int($value)) {
        $limit = intdiv(PHP_INT_MAX, $nanosecondsPerUnit);

        if ($value > $limit || $value < -$limit) {
            throw outOfRange();
        }

        return fromNanoseconds($value * $nanosecondsPerUnit);
    }

    if (!is_finite($value)) {
        // var_export(): PHP 8.5 warns when %s coerces NAN to a string.
        throw new \ValueError(\sprintf('A duration must be finite, %s given', var_export($value, return: true)));
    }

    $nanoseconds = round($value * $nanosecondsPerUnit);

    // 2^63: PHP_INT_MAX is not representable as a float, and -2^63 would overflow when negated.
    if ($nanoseconds >= 9.223_372_036_854_775_808e18 || $nanoseconds <= -9.223_372_036_854_775_808e18) {
        throw outOfRange();
    }

    return fromNanoseconds((int) $nanoseconds);
}

/**
 * @internal
 */
function fromNanoseconds(int $nanoseconds): Duration
{
    if ($nanoseconds >= 0) {
        return Duration::fromNanoseconds($nanoseconds);
    }

    if ($nanoseconds === PHP_INT_MIN) {
        throw outOfRange();
    }

    return Duration::fromNanoseconds(-$nanoseconds)->negate();
}

/**
 * Integer division with the given rounding, exact in the whole int range.
 *
 * @internal
 * @param positive-int $divisor
 */
function divide(int $dividend, int $divisor, \RoundingMode $rounding): int
{
    $quotient = intdiv($dividend, $divisor);
    $remainder = abs($dividend % $divisor);

    if ($remainder === 0) {
        return $quotient;
    }

    $awayFromZero = $dividend < 0 ? $quotient - 1 : $quotient + 1;

    // $remainder < $divisor - $remainder is 2 * $remainder < $divisor without an overflow.
    $half = $remainder <=> $divisor - $remainder;

    return match ($rounding) {
        \RoundingMode::TowardsZero => $quotient,
        \RoundingMode::AwayFromZero => $awayFromZero,
        \RoundingMode::PositiveInfinity => $dividend > 0 ? $awayFromZero : $quotient,
        \RoundingMode::NegativeInfinity => $dividend < 0 ? $awayFromZero : $quotient,
        \RoundingMode::HalfAwayFromZero => $half >= 0 ? $awayFromZero : $quotient,
        \RoundingMode::HalfTowardsZero => $half > 0 ? $awayFromZero : $quotient,
        \RoundingMode::HalfEven => $half > 0 || ($half === 0 && $quotient % 2 !== 0) ? $awayFromZero : $quotient,
        \RoundingMode::HalfOdd => $half > 0 || ($half === 0 && $quotient % 2 === 0) ? $awayFromZero : $quotient,
    };
}

/**
 * The same exception as Time\Duration throws, so a caller handles one kind.
 *
 * @internal
 */
function outOfRange(): TimeException
{
    return new TimeException('The maximum representable range is 9_223_372_035 seconds (roughly 292 years)');
}

/**
 * @internal
 * @param non-negative-int $value
 * @param positive-int $unit a power of 10
 */
function decimal(int $value, int $unit): string
{
    $whole = intdiv($value, $unit);
    $fraction = $value % $unit;

    if ($fraction === 0) {
        return (string) $whole;
    }

    $digits = \strlen((string) $unit) - 1;

    return $whole . '.' . rtrim(str_pad((string) $fraction, $digits, '0', STR_PAD_LEFT), '0');
}
