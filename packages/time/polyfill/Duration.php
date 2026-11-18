<?php

declare(strict_types=1);

namespace Time;

use Thesis\Time\Internal\IsoInterval;

/**
 * Polyfill of the class of PHP 8.6 (https://wiki.php.net/rfc/duration_class), loaded by polyfill/load.php.
 * It follows ext/date/time_duration.c and lib/duration.c of timelib down to the exception messages.
 */
final readonly class Duration
{
    /**
     * On 32-bit PHP the range is that of the int $seconds.
     */
    private const MAX_SECONDS = \PHP_INT_SIZE === 4 ? PHP_INT_MAX : 9_223_372_035;
    private const NANOSECONDS_PER_SECOND = 1_000_000_000;
    private const SECONDS_PER_MINUTE = 60;
    private const SECONDS_PER_HOUR = 60 * self::SECONDS_PER_MINUTE;
    private const OUT_OF_RANGE = \PHP_INT_SIZE === 4
        ? 'The maximum representable range is 2_147_483_647 seconds (roughly 68 years)'
        : 'The maximum representable range is 9_223_372_035 seconds (roughly 292 years)';
    private const PARSE_FAILURE = 'The ISO 8601 duration string could not be parsed';
    private const MISSING_PERIOD = 'The ISO 8601 duration string is missing the period (P) aspect';
    private const ONLY_PERIOD_ALLOWED = 'The ISO 8601 duration string may only contain the period (P) aspect';
    private const DAYS_FOUND = 'The ISO 8601 duration string may only contain the time (T) aspect';

    /**
     * First on purpose: PHP compares objects property by property in declaration order, so `<`, `<=>` and `==`
     * compare the signed seconds, then the signed nanoseconds, as the C class does. Int nanoseconds overflow on 32-bit.
     */
    private int $signedSeconds;

    private int $signedNanoseconds;

    /**
     * Up to 9_223_372_035, on 32-bit PHP up to PHP_INT_MAX.
     *
     * @var non-negative-int
     */
    public int $seconds;

    /**
     * @var int<0, 999999999>
     */
    public int $nanoseconds;

    public bool $negative;

    /**
     * @param int|float $seconds a float is an int that overflowed in the arithmetic of the caller, above the range
     */
    private function __construct(int|float $seconds, int $nanoseconds, bool $negative)
    {
        if (\is_float($seconds) || $seconds > self::MAX_SECONDS) {
            throw new TimeException(self::OUT_OF_RANGE);
        }

        \assert($seconds >= 0 && $nanoseconds >= 0 && $nanoseconds < self::NANOSECONDS_PER_SECOND);

        $negative = $negative && ($seconds !== 0 || $nanoseconds !== 0);
        $this->signedSeconds = $negative ? -$seconds : $seconds;
        $this->signedNanoseconds = $negative ? -$nanoseconds : $nanoseconds;
        $this->seconds = $seconds;
        $this->nanoseconds = $nanoseconds;
        $this->negative = $negative;
    }

    public static function fromSeconds(int $seconds, int $nanoseconds = 0): self
    {
        if ($seconds < 0) {
            throw self::negativeArgument('fromSeconds', 1, 'seconds');
        }

        if ($nanoseconds < 0) {
            throw self::negativeArgument('fromSeconds', 2, 'nanoseconds');
        }

        if ($nanoseconds >= self::NANOSECONDS_PER_SECOND) {
            throw new \ValueError('Time\Duration::fromSeconds(): Argument #2 ($nanoseconds) must be less than 1_000_000_000');
        }

        return new self($seconds, $nanoseconds, false);
    }

    public static function fromNanoseconds(int $nanoseconds): self
    {
        if ($nanoseconds < 0) {
            throw self::negativeArgument('fromNanoseconds', 1, 'nanoseconds');
        }

        return new self(
            seconds: intdiv($nanoseconds, self::NANOSECONDS_PER_SECOND),
            nanoseconds: $nanoseconds % self::NANOSECONDS_PER_SECOND,
            negative: false,
        );
    }

    public static function fromMicroseconds(int $microseconds): self
    {
        if ($microseconds < 0) {
            throw self::negativeArgument('fromMicroseconds', 1, 'microseconds');
        }

        return new self(
            seconds: intdiv($microseconds, 1_000_000),
            nanoseconds: $microseconds % 1_000_000 * 1000,
            negative: false,
        );
    }

    public static function fromMilliseconds(int $milliseconds): self
    {
        if ($milliseconds < 0) {
            throw self::negativeArgument('fromMilliseconds', 1, 'milliseconds');
        }

        return new self(
            seconds: intdiv($milliseconds, 1000),
            nanoseconds: $milliseconds % 1000 * 1_000_000,
            negative: false,
        );
    }

    public static function fromMinutes(int $minutes): self
    {
        if ($minutes < 0) {
            throw self::negativeArgument('fromMinutes', 1, 'minutes');
        }

        return new self($minutes * self::SECONDS_PER_MINUTE, 0, false);
    }

    public static function fromHours(int $hours): self
    {
        if ($hours < 0) {
            throw self::negativeArgument('fromHours', 1, 'hours');
        }

        return new self($hours * self::SECONDS_PER_HOUR, 0, false);
    }

    public static function fromIso8601DurationString(string $specification): self
    {
        if (str_contains($specification, "\0")) {
            throw new TimeException(self::PARSE_FAILURE);
        }

        $interval = IsoInterval::parse($specification);

        if ($interval === null) {
            throw new TimeException(self::PARSE_FAILURE);
        }

        if (!$interval->hasPeriod) {
            throw new TimeException(self::MISSING_PERIOD);
        }

        if ($interval->hasDate || $interval->hasRecurrences) {
            throw new TimeException(self::ONLY_PERIOD_ALLOWED);
        }

        if ($interval->years > 0 || $interval->months > 0 || $interval->days > 0) {
            throw new TimeException(self::DAYS_FOUND);
        }

        $seconds = $interval->hours * self::SECONDS_PER_HOUR
            + $interval->minutes * self::SECONDS_PER_MINUTE
            + $interval->seconds;

        return new self($seconds, 0, false);
    }

    public static function compare(self $a, self $b): int
    {
        return [$a->signedSeconds, $a->signedNanoseconds] <=> [$b->signedSeconds, $b->signedNanoseconds];
    }

    /**
     * @param array<mixed> $data
     */
    public function __unserialize(array $data): void
    {
        // Assigned as they come, like object_properties_load() does: the engine rejects a wrong type
        // and a second unserialization, and its error becomes the previous one.
        try {
            foreach ($data as $name => $value) {
                match ($name) {
                    /** @phpstan-ignore assign.propertyType */
                    'seconds' => $this->seconds = $value,
                    /** @phpstan-ignore assign.propertyType */
                    'nanoseconds' => $this->nanoseconds = $value,
                    /** @phpstan-ignore assign.propertyType */
                    'negative' => $this->negative = $value,
                    default => throw new \Error(\sprintf('Cannot create dynamic property Time\Duration::$%s', $name)),
                };
            }
        } catch (\Error $error) {
            throw self::invalidSerializationData($error);
        }

        if (!isset($this->seconds, $this->nanoseconds, $this->negative)) {
            throw self::invalidSerializationData();
        }

        // The properties hold whatever ints came, their PHPDoc ranges are what is checked here.
        $seconds = $data['seconds'] ?? null;
        $nanoseconds = $data['nanoseconds'] ?? null;
        \assert(\is_int($seconds) && \is_int($nanoseconds));

        if (
            $seconds < 0
            || $nanoseconds < 0
            || $nanoseconds >= self::NANOSECONDS_PER_SECOND
            || ($this->negative && $seconds === 0 && $nanoseconds === 0)
        ) {
            throw self::invalidSerializationData();
        }

        if ($seconds > self::MAX_SECONDS) {
            throw self::invalidSerializationData(new TimeException(self::OUT_OF_RANGE));
        }

        $this->signedSeconds = $this->negative ? -$seconds : $seconds;
        $this->signedNanoseconds = $this->negative ? -$nanoseconds : $nanoseconds;
    }

    /**
     * @return array{seconds: int, nanoseconds: int, negative: bool}
     */
    public function __serialize(): array
    {
        return [
            'seconds' => $this->seconds,
            'nanoseconds' => $this->nanoseconds,
            'negative' => $this->negative,
        ];
    }

    public function negate(): self
    {
        return new self($this->seconds, $this->nanoseconds, !$this->negative);
    }

    public function absolute(): self
    {
        return $this->negative ? new self($this->seconds, $this->nanoseconds, false) : $this;
    }

    public function add(self $duration): self
    {
        if ($this->negative === $duration->negative) {
            return self::addMagnitudes($this, $duration);
        }

        return match (self::compareMagnitudes($this, $duration)) {
            -1 => self::subtractMagnitudes($duration, $this),
            0 => new self(0, 0, false),
            1 => self::subtractMagnitudes($this, $duration),
        };
    }

    public function sub(self $duration): self
    {
        if ($this->negative !== $duration->negative) {
            return self::addMagnitudes($this, $duration);
        }

        return match (self::compareMagnitudes($this, $duration)) {
            -1 => self::subtractMagnitudes($duration, $this)->negate(),
            0 => new self(0, 0, false),
            1 => self::subtractMagnitudes($this, $duration),
        };
    }

    /**
     * Mirrors timelib_duration_mul_static(): a product above 2^64 - 1 is an "Integer Overflow",
     * a smaller one above the range is out of range.
     */
    public function multiplyBy(int $factor): self
    {
        if ($factor < 0) {
            throw self::negativeArgument('multiplyBy', 1, 'factor');
        }

        [$extraSeconds, $nanoseconds] = self::multiplyNanoseconds($this->nanoseconds, $factor);

        if (self::exceedsUint64($this->seconds, $factor, $extraSeconds)) {
            throw new TimeException('Failed to create a Time\Duration: Integer Overflow');
        }

        return new self($this->seconds * $factor + $extraSeconds, $nanoseconds, $this->negative);
    }

    public function divideBy(int $divisor): self
    {
        if ($divisor < 0) {
            throw self::negativeArgument('divideBy', 1, 'divisor');
        }

        if ($divisor === 0) {
            throw new \DivisionByZeroError('Division by zero');
        }

        return new self(
            seconds: intdiv($this->seconds, $divisor),
            nanoseconds: self::divideRemainder($this->seconds % $divisor, $this->nanoseconds, $divisor),
            negative: $this->negative,
        );
    }

    private static function addMagnitudes(self $original, self $additional): self
    {
        $seconds = $original->seconds + $additional->seconds;
        $nanoseconds = $original->nanoseconds + $additional->nanoseconds;

        if ($nanoseconds >= self::NANOSECONDS_PER_SECOND) {
            ++$seconds;
            $nanoseconds -= self::NANOSECONDS_PER_SECOND;
        }

        return new self($seconds, $nanoseconds, $original->negative);
    }

    /**
     * The magnitude of $original is not less than the magnitude of $minus.
     */
    private static function subtractMagnitudes(self $original, self $minus): self
    {
        $seconds = $original->seconds - $minus->seconds;
        $nanoseconds = $original->nanoseconds - $minus->nanoseconds;

        if ($nanoseconds < 0) {
            --$seconds;
            $nanoseconds += self::NANOSECONDS_PER_SECOND;
        }

        return new self($seconds, $nanoseconds, $original->negative);
    }

    /**
     * @return -1|0|1
     */
    private static function compareMagnitudes(self $a, self $b): int
    {
        return [$a->seconds, $a->nanoseconds] <=> [$b->seconds, $b->nanoseconds];
    }

    /**
     * $nanoseconds * $factor as whole seconds and the nanoseconds left. The product itself overflows int
     * on 32-bit PHP, so it is the sum of the doublings of $nanoseconds for the bits of $factor.
     *
     * @param non-negative-int $factor
     * @return array{non-negative-int, int<0, 999999999>} the seconds are less than $factor
     */
    private static function multiplyNanoseconds(int $nanoseconds, int $factor): array
    {
        $product = [0, 0];
        $doubling = [0, $nanoseconds];

        for (; $factor > 0; $factor >>= 1) {
            if (($factor & 1) === 1) {
                $product = self::sum($product, $doubling);
            }

            $doubling = self::sum($doubling, $doubling);
        }

        /** @var array{non-negative-int, int<0, 999999999>} */
        return $product;
    }

    /**
     * ($seconds * 1_000_000_000 + $nanoseconds) / $divisor, rounded down. The dividend overflows int
     * on 32-bit PHP, so it is divided in binary: the multiples $divisor * 2^i are subtracted from the largest.
     *
     * @param positive-int $divisor greater than $seconds, so the quotient is less than a second
     * @return int<0, 999999999>
     */
    private static function divideRemainder(int $seconds, int $nanoseconds, int $divisor): int
    {
        $rest = [$seconds, $nanoseconds];
        $multiples = [];

        for ($multiple = self::split($divisor); $multiple <= $rest; $multiple = self::sum($multiple, $multiple)) {
            $multiples[] = $multiple;
        }

        $quotient = 0;

        foreach (array_reverse($multiples, preserve_keys: true) as $power => $multiple) {
            if ($multiple <= $rest) {
                $rest = self::difference($rest, $multiple);
                $quotient += 1 << $power;
            }
        }

        /** @var int<0, 999999999> */
        return $quotient;
    }

    /**
     * @return array{int, int} the seconds and the nanoseconds
     */
    private static function split(int $nanoseconds): array
    {
        return [intdiv($nanoseconds, self::NANOSECONDS_PER_SECOND), $nanoseconds % self::NANOSECONDS_PER_SECOND];
    }

    /**
     * @param array{int, int} $first seconds and nanoseconds
     * @param array{int, int} $second seconds and nanoseconds
     * @return array{int, int}
     */
    private static function sum(array $first, array $second): array
    {
        $nanoseconds = $first[1] + $second[1];

        if ($nanoseconds >= self::NANOSECONDS_PER_SECOND) {
            return [$first[0] + $second[0] + 1, $nanoseconds - self::NANOSECONDS_PER_SECOND];
        }

        return [$first[0] + $second[0], $nanoseconds];
    }

    /**
     * @param array{int, int} $first seconds and nanoseconds, not less than $second
     * @param array{int, int} $second seconds and nanoseconds
     * @return array{int, int}
     */
    private static function difference(array $first, array $second): array
    {
        $nanoseconds = $first[1] - $second[1];

        if ($nanoseconds < 0) {
            return [$first[0] - $second[0] - 1, $nanoseconds + self::NANOSECONDS_PER_SECOND];
        }

        return [$first[0] - $second[0], $nanoseconds];
    }

    /**
     * Whether $seconds * $factor + $addend > 2^64 - 1, without leaving int64: 2^64 - 1 = 2 * PHP_INT_MAX + 1.
     *
     * @param non-negative-int $seconds
     * @param non-negative-int $factor
     * @param non-negative-int $addend less than $factor
     */
    private static function exceedsUint64(int $seconds, int $factor, int $addend): bool
    {
        // On 32-bit PHP both are below 2^31, and the product is below 2^62. On 64-bit PHP $seconds is below 2^34,
        // so the product of a factor of 1 or 2 stays far from the limit.
        if (\PHP_INT_SIZE === 4 || $factor <= 2) {
            return false;
        }

        // floor((2^64 - 1 - $addend) / $factor) = floor((PHP_INT_MAX + $rest + 1) / $factor)
        $rest = PHP_INT_MAX - $addend;
        $remainderOfMax = PHP_INT_MAX % $factor;
        $remainderOfRest = $rest % $factor;
        $limit = intdiv(PHP_INT_MAX, $factor) + intdiv($rest, $factor)
            + ($remainderOfMax + 1 >= $factor - $remainderOfRest ? 1 : 0);

        return $seconds > $limit;
    }

    private static function negativeArgument(string $method, int $position, string $name): \ValueError
    {
        return new \ValueError(\sprintf(
            'Time\Duration::%s(): Argument #%d ($%s) must be greater than or equal to 0',
            $method,
            $position,
            $name,
        ));
    }

    private static function invalidSerializationData(?\Throwable $previous = null): \Exception
    {
        return new \Exception('Invalid serialization data for Time\Duration object', previous: $previous);
    }
}
