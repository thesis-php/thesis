<?php

declare(strict_types=1);

namespace Time;

use Thesis\Time\Internal\IsoInterval;

/**
 * Polyfill of the PHP 8.6 class (https://wiki.php.net/rfc/duration_class) for PHP 8.5, loaded by
 * src/autoload.files.php only when the class does not exist. It follows the C implementation
 * (ext/date/time_duration.c and lib/duration.c of timelib) down to the exception messages.
 */
final readonly class Duration
{
    private const int MAX_SECONDS = 9_223_372_035;
    private const int NANOSECONDS_PER_SECOND = 1_000_000_000;
    private const string OUT_OF_RANGE = 'The maximum representable range is 9_223_372_035 seconds (roughly 292 years)';
    private const string PARSE_FAILURE = 'The ISO 8601 duration string could not be parsed';
    private const string MISSING_PERIOD = 'The ISO 8601 duration string is missing the period (P) aspect';
    private const string ONLY_PERIOD_ALLOWED = 'The ISO 8601 duration string may only contain the period (P) aspect';
    private const string DAYS_FOUND = 'The ISO 8601 duration string may only contain the time (T) aspect';

    /**
     * Declared first on purpose: PHP compares two objects of one class property by property in the order
     * of declaration, so `<`, `<=>` and `==` decide by this signed value, like the comparison handler of the C class.
     */
    private int $signedNanoseconds;

    /**
     * @var int<0, 9223372035>
     */
    public int $seconds;

    /**
     * @var int<0, 999999999>
     */
    public int $nanoseconds;

    public bool $negative;

    private function __construct(int $seconds, int $nanoseconds, bool $negative)
    {
        if ($seconds > self::MAX_SECONDS) {
            throw new TimeException(self::OUT_OF_RANGE);
        }

        \assert($seconds >= 0 && $nanoseconds >= 0 && $nanoseconds < self::NANOSECONDS_PER_SECOND);

        $negative = $negative && ($seconds !== 0 || $nanoseconds !== 0);
        $magnitude = $seconds * self::NANOSECONDS_PER_SECOND + $nanoseconds;
        $this->signedNanoseconds = $negative ? -$magnitude : $magnitude;
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
            nanoseconds: $microseconds % 1_000_000 * 1_000,
            negative: false,
        );
    }

    public static function fromMilliseconds(int $milliseconds): self
    {
        if ($milliseconds < 0) {
            throw self::negativeArgument('fromMilliseconds', 1, 'milliseconds');
        }

        return new self(
            seconds: intdiv($milliseconds, 1_000),
            nanoseconds: $milliseconds % 1_000 * 1_000_000,
            negative: false,
        );
    }

    public static function fromMinutes(int $minutes): self
    {
        if ($minutes < 0) {
            throw self::negativeArgument('fromMinutes', 1, 'minutes');
        }

        if ($minutes > intdiv(self::MAX_SECONDS, 60)) {
            throw new TimeException(self::OUT_OF_RANGE);
        }

        return new self($minutes * 60, 0, false);
    }

    public static function fromHours(int $hours): self
    {
        if ($hours < 0) {
            throw self::negativeArgument('fromHours', 1, 'hours');
        }

        if ($hours > intdiv(self::MAX_SECONDS, 3_600)) {
            throw new TimeException(self::OUT_OF_RANGE);
        }

        return new self($hours * 3_600, 0, false);
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

        return new self($interval->hours * 3_600 + $interval->minutes * 60 + $interval->seconds, 0, false);
    }

    public static function compare(self $a, self $b): int
    {
        return $a->signedNanoseconds <=> $b->signedNanoseconds;
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

        $magnitude = $seconds * self::NANOSECONDS_PER_SECOND + $nanoseconds;
        $this->signedNanoseconds = $this->negative ? -$magnitude : $magnitude;
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

        if ($factor === 0) {
            return new self(0, 0, false);
        }

        $extraSeconds = $this->nanoseconds * intdiv($factor, self::NANOSECONDS_PER_SECOND);
        $nanoseconds = $this->nanoseconds * ($factor % self::NANOSECONDS_PER_SECOND);
        $extraSeconds += intdiv($nanoseconds, self::NANOSECONDS_PER_SECOND);
        $nanoseconds %= self::NANOSECONDS_PER_SECOND;
        \assert($extraSeconds >= 0);

        if (self::exceedsUint64($this->seconds, $factor, $extraSeconds)) {
            throw new TimeException('Failed to create a Time\Duration: Integer Overflow');
        }

        if (
            $extraSeconds > self::MAX_SECONDS
            || $this->seconds > intdiv(self::MAX_SECONDS - $extraSeconds, $factor)
        ) {
            throw new TimeException(self::OUT_OF_RANGE);
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
            nanoseconds: intdiv(
                $this->nanoseconds + $this->seconds % $divisor * self::NANOSECONDS_PER_SECOND,
                $divisor,
            ),
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
     * Whether $seconds * $factor + $addend > 2^64 - 1, without leaving int64: 2^64 - 1 = 2 * PHP_INT_MAX + 1.
     *
     * @param non-negative-int $seconds
     * @param positive-int $factor
     * @param non-negative-int $addend less than $factor
     */
    private static function exceedsUint64(int $seconds, int $factor, int $addend): bool
    {
        // $seconds is below 2^34, so the product of a factor of 1 or 2 stays far from the limit.
        if ($factor <= 2) {
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
