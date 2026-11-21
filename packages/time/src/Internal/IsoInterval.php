<?php

declare(strict_types=1);

namespace Thesis\Time\Internal;

/**
 * The scanner of timelib_strtointerval() (lib/parse_iso_intervals.re of timelib) for the Time\Duration polyfill:
 * the C class accepts exactly what it accepts, separators, outer whitespace, repeated periods and
 * the combined P0000-00-00T00:00:00 form included. Like re2c, a token is the longest match, the earlier rule on a tie.
 *
 * @internal
 */
final class IsoInterval
{
    private const MONTH = '(?:0[1-9]|1[0-2])';
    private const MONTH_OR_ZERO = '(?:0[0-9]|1[0-2])';
    private const DAY = '(?:0[1-9]|[12][0-9]|3[01])';
    private const DAY_OR_ZERO = '(?:0[0-9]|[12][0-9]|3[01])';
    private const HOUR = '(?:[01][0-9]|2[0-4])';
    private const MINUTE = '[0-5][0-9]';
    private const RECURRENCES = '/R[0-9]+/A';
    private const DATE_TIME = '/[0-9]{4}' . self::MONTH . self::DAY
        . 'T' . self::HOUR . self::MINUTE . self::MINUTE . 'Z'
        . '|[0-9]{4}-' . self::MONTH . '-' . self::DAY
        . 'T' . self::HOUR . ':' . self::MINUTE . ':' . self::MINUTE . 'Z/A';
    private const PERIOD = '/P(?:[0-9]+Y)?(?:[0-9]+M)?(?:[0-9]+W)?(?:[0-9]+D)?'
        . '(?:T(?:[0-9]+H)?(?:[0-9]+M)?(?:[0-9]+S)?)?/A';
    private const COMBINED_PERIOD = '/P[0-9]{4}-' . self::MONTH_OR_ZERO . '-' . self::DAY_OR_ZERO
        . 'T' . self::HOUR . ':' . self::MINUTE . ':' . self::MINUTE . '/A';
    private const SKIPPED = " .,\t/\n";
    private const MAX_NUMBER_DIGITS = 12;

    public bool $hasPeriod = false;

    /**
     * The pattern matches a start or end date.
     */
    public bool $hasDate = false;

    public bool $hasRecurrences = false;

    /**
     * @var non-negative-int|float
     */
    public int|float $years = 0;

    /**
     * @var non-negative-int|float
     */
    public int|float $months = 0;

    /**
     * Weeks are added as 7 days.
     *
     * @var non-negative-int|float
     */
    public int|float $days = 0;

    /**
     * @var non-negative-int|float
     */
    public int|float $hours = 0;

    /**
     * @var non-negative-int|float
     */
    public int|float $minutes = 0;

    /**
     * @var non-negative-int|float
     */
    public int|float $seconds = 0;

    private function __construct() {}

    /**
     * @return ?self null if timelib reports an error
     */
    public static function parse(string $string): ?self
    {
        if ($string === '') {
            return null;
        }

        // timelib trims C isspace() characters, but keeps the last one of a string made of them only.
        $trimmed = trim($string, " \t\n\v\f\r");
        $interval = new self();

        return $interval->scan($trimmed === '' ? substr($string, -1) : $trimmed) ? $interval : null;
    }

    private function scan(string $string): bool
    {
        $offset = 0;
        $length = \strlen($string);

        while ($offset < $length) {
            [$rule, $token] = self::longestToken($string, $offset);

            if ($token === '') {
                if (!str_contains(self::SKIPPED, $string[$offset])) {
                    return false;
                }

                ++$offset;

                continue;
            }

            $offset += \strlen($token);

            $scanned = match ($rule) {
                self::RECURRENCES => $this->hasRecurrences = true,
                self::DATE_TIME => $this->hasDate = true,
                self::PERIOD => $this->scanPeriod($token),
                default => $this->scanCombinedPeriod($token),
            };

            if (!$scanned) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{string, string} the rule and the token, empty if no rule matches
     */
    private static function longestToken(string $string, int $offset): array
    {
        $longest = ['', ''];

        foreach ([self::RECURRENCES, self::DATE_TIME, self::PERIOD, self::COMBINED_PERIOD] as $rule) {
            if (preg_match($rule, $string, $matches, offset: $offset) !== 1) {
                continue;
            }

            // No two rules match a token of the same length: they differ in the first byte,
            // or COMBINED_PERIOD has a hyphen where PERIOD cannot.
            // @infection-ignore GreaterThan
            if (\strlen($matches[0]) > \strlen($longest[1])) {
                $longest = [$rule, $matches[0]];
            }
        }

        return $longest;
    }

    /**
     * The token matches PERIOD, so every number is followed by a designator.
     */
    private function scanPeriod(string $token): bool
    {
        $position = 1;
        $length = \strlen($token);
        $inTime = false;

        do {
            if (($token[$position] ?? '') === 'T') {
                $inTime = true;
                ++$position;
            }

            // A longer number leaves a digit where the designator should be, a missing one (P, PT) nothing.
            $digits = strspn($token, '0123456789', $position, self::MAX_NUMBER_DIGITS);
            $number = self::number(substr($token, $position, $digits));
            $position += $digits;

            switch ($token[$position] ?? '') {
                case 'Y':
                    $this->years = $number;
                    break;
                case 'W':
                    $this->days += $number * 7;
                    break;
                case 'D':
                    $this->days += $number;
                    break;
                case 'H':
                    $this->hours = $number;
                    break;
                case 'M':
                    if ($inTime) {
                        $this->minutes = $number;
                    } else {
                        $this->months = $number;
                    }
                    break;
                case 'S':
                    $this->seconds = $number;
                    break;

                default:
                    return false;
            }

            ++$position;
        } while ($position < $length);

        $this->hasPeriod = true;

        return true;
    }

    /**
     * Timelib reads up to 12 digits into int64. Above PHP_INT_MAX, on 32-bit PHP, the number stays a float:
     * exact below 2^53, and far above the range of a duration.
     *
     * @return non-negative-int|float
     */
    private static function number(string $digits): int|float
    {
        // Mutation testing runs on 64-bit PHP, where 12 digits never pass PHP_INT_MAX: the 32-bit tests cover it.
        // @infection-ignore CastFloat
        $number = (float) $digits;

        // @infection-ignore GreaterThan
        return $number > PHP_INT_MAX ? $number : (int) $digits;
    }

    /**
     * The token matches COMBINED_PERIOD: PYYYY-MM-DDThh:mm:ss.
     */
    private function scanCombinedPeriod(string $token): bool
    {
        /**
         * @var array{
         *     non-negative-int,
         *     non-negative-int,
         *     non-negative-int,
         *     non-negative-int,
         *     non-negative-int,
         *     non-negative-int,
         * } $numbers the token matches the format
         */
        $numbers = sscanf($token, 'P%4d-%2d-%2dT%2d:%2d:%2d');
        [$this->years, $this->months, $this->days, $this->hours, $this->minutes, $this->seconds] = $numbers;
        $this->hasPeriod = true;

        return true;
    }
}
