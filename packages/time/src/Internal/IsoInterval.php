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
    private const string MONTH = '(?:0[1-9]|1[0-2])';
    private const string MONTH_OR_ZERO = '(?:0[0-9]|1[0-2])';
    private const string DAY = '(?:0[1-9]|[12][0-9]|3[01])';
    private const string DAY_OR_ZERO = '(?:0[0-9]|[12][0-9]|3[01])';
    private const string HOUR = '(?:[01][0-9]|2[0-4])';
    private const string MINUTE = '[0-5][0-9]';
    private const string RECURRENCES = '/R[0-9]+/A';
    private const string DATE_TIME = '/[0-9]{4}' . self::MONTH . self::DAY
        . 'T' . self::HOUR . self::MINUTE . self::MINUTE . 'Z'
        . '|[0-9]{4}-' . self::MONTH . '-' . self::DAY
        . 'T' . self::HOUR . ':' . self::MINUTE . ':' . self::MINUTE . 'Z/A';
    private const string PERIOD = '/P(?:[0-9]+Y)?(?:[0-9]+M)?(?:[0-9]+W)?(?:[0-9]+D)?'
        . '(?:T(?:[0-9]+H)?(?:[0-9]+M)?(?:[0-9]+S)?)?/A';
    private const string COMBINED_PERIOD = '/P[0-9]{4}-' . self::MONTH_OR_ZERO . '-' . self::DAY_OR_ZERO
        . 'T' . self::HOUR . ':' . self::MINUTE . ':' . self::MINUTE . '/A';
    private const string SKIPPED = " .,\t/\n";
    private const int MAX_NUMBER_DIGITS = 12;

    public private(set) bool $hasPeriod = false;

    /**
     * A start or an end date.
     */
    public private(set) bool $hasDate = false;

    public private(set) bool $hasRecurrences = false;

    /**
     * @var non-negative-int
     */
    public private(set) int $years = 0;

    /**
     * @var non-negative-int
     */
    public private(set) int $months = 0;

    /**
     * Weeks are added as 7 days.
     *
     * @var non-negative-int
     */
    public private(set) int $days = 0;

    /**
     * @var non-negative-int
     */
    public private(set) int $hours = 0;

    /**
     * @var non-negative-int
     */
    public private(set) int $minutes = 0;

    /**
     * @var non-negative-int
     */
    public private(set) int $seconds = 0;

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

            if ($position >= $length) {
                return false;
            }

            // A longer number leaves a digit where the designator should be.
            $digits = strspn($token, '0123456789', $position, self::MAX_NUMBER_DIGITS);
            $number = (int) substr($token, $position, $digits);
            \assert($number >= 0);
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
     * The token matches COMBINED_PERIOD: PYYYY-MM-DDThh:mm:ss.
     */
    private function scanCombinedPeriod(string $token): bool
    {
        $this->years = self::digits($token, 1, 4);
        $this->months = self::digits($token, 6, 2);
        $this->days = self::digits($token, 9, 2);
        $this->hours = self::digits($token, 12, 2);
        $this->minutes = self::digits($token, 15, 2);
        $this->seconds = self::digits($token, 18, 2);
        $this->hasPeriod = true;

        return true;
    }

    /**
     * @return non-negative-int
     */
    private static function digits(string $token, int $offset, int $length): int
    {
        $number = (int) substr($token, $offset, $length);
        \assert($number >= 0);

        return $number;
    }
}
