<?php

declare(strict_types=1);

namespace Thesis\Time;

use Random\Engine\Mt19937;
use Random\Randomizer;
use Testo\Assert;
use Testo\Core\Exception\SkipTest;
use Testo\Data\DataProvider;
use Testo\Test;
use Time\Duration;

/**
 * The same random operations on a renamed copy of the polyfill and on the class of PHP 8.6 give the same results
 * and exceptions. Runs on PHP 8.6 only. To reproduce a failure, run the reported seed alone.
 */
#[Test]
final class DurationFuzzTest
{
    private const CASES_PER_SEED = 2000;
    private const ISO_PIECES = [
        'P', 'T', 'R', 'Y', 'M', 'W', 'D', 'H', 'S', 'Z', '0', '1', '2', '5', '9', '00', '12', '24', '59', '3600',
        '999999999999', '9999999999999', '-', ':', ' ', '.', ',', '/', "\t", "\n", "\r", "\v", "\0", 'x',
        '2008', 'P0000-00-00T', 'T00:00:00', '20080101T000000Z', '2008-01-01T00:00:00Z', 'R5', 'PT', 'P1D',
    ];
    private const EDGE_INTS = [
        0, 1, 2, 3, 7, 999, 1000, 999_999_999, 1_000_000_000, 1_000_000_001, 1_999_999_999, 2_000_000_001,
        3_074_457_345, 4_611_686_018, 9_223_372_035, 9_223_372_036, 4_611_686_018_427_387_904, PHP_INT_MAX - 1,
        PHP_INT_MAX,
    ];
    private const EDGE_INTS_OF_32_BITS = [
        0, 1, 2, 3, 7, 999, 1000, 999_999_999, 1_000_000_000, 1_000_000_001, 1_999_999_999, 2_000_000_001,
        715_827_882, 1_073_741_823, 1_073_741_824, PHP_INT_MAX - 1, PHP_INT_MAX,
    ];

    /**
     * @return iterable<string, array{int}>
     */
    public static function seeds(): iterable
    {
        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10] as $seed) {
            yield "seed {$seed}" => [$seed];
        }
    }

    #[DataProvider('seeds')]
    public function parsesIsoStringsAsPhp86(int $seed): void
    {
        $polyfill = self::polyfill();
        $random = new Randomizer(new Mt19937($seed));

        for ($case = 0; $case < self::CASES_PER_SEED; ++$case) {
            $string = $random->getInt(0, 1) === 0 ? self::pieces($random) : self::period($random);

            Assert::same(
                actual: outcome(static fn() => $polyfill::fromIso8601DurationString($string)),
                expected: outcome(static fn() => Duration::fromIso8601DurationString($string)),
                message: \sprintf('seed %d, case %d: %s', $seed, $case, json_encode($string, JSON_THROW_ON_ERROR)),
            );
        }
    }

    #[DataProvider('seeds')]
    public function calculatesAsPhp86(int $seed): void
    {
        $polyfill = self::polyfill();
        $random = new Randomizer(new Mt19937($seed));

        for ($case = 0; $case < self::CASES_PER_SEED; ++$case) {
            $a = [self::int($random), $random->getInt(0, 999_999_999), $random->getInt(0, 1) === 1];
            $b = [self::int($random), $random->getInt(0, 999_999_999), $random->getInt(0, 1) === 1];
            $operand = self::int($random);
            $operation = $random->getInt(0, 7);

            Assert::same(
                actual: outcome(static fn() => self::apply($polyfill, $operation, $a, $b, $operand)),
                expected: outcome(static fn() => self::apply(Duration::class, $operation, $a, $b, $operand)),
                message: \sprintf('seed %d, case %d: operation %d of %s', $seed, $case, $operation, json_encode(
                    [$a, $b, $operand],
                    JSON_THROW_ON_ERROR,
                )),
            );
        }
    }

    /**
     * @param class-string<Duration> $class
     * @param array{int, int, bool} $a
     * @param array{int, int, bool} $b
     */
    private static function apply(string $class, int $operation, array $a, array $b, int $operand): mixed
    {
        return match ($operation) {
            0 => self::create($class, $a)->add(self::create($class, $b)),
            1 => self::create($class, $a)->sub(self::create($class, $b)),
            2 => self::create($class, $a)->multiplyBy($operand),
            3 => self::create($class, $a)->divideBy($operand),
            4 => [
                $class::compare(self::create($class, $a), self::create($class, $b)),
                self::create($class, $a) < self::create($class, $b),
                self::create($class, $a) <=> self::create($class, $b),
            ],
            5 => [
                outcome(static fn() => $class::fromNanoseconds($operand)),
                outcome(static fn() => $class::fromMicroseconds($operand)),
                outcome(static fn() => $class::fromMilliseconds($operand)),
                outcome(static fn() => $class::fromMinutes($operand)),
                outcome(static fn() => $class::fromHours($operand)),
            ],
            // The serialized form names the class, the copy of the polyfill has another name.
            6 => [
                self::create($class, $a)->negate(),
                self::create($class, $a)->absolute(),
                str_replace(
                    search: \sprintf('O:%d:"%s"', \strlen($class), $class),
                    replace: 'O:13:"Time\Duration"',
                    subject: serialize(self::create($class, $a)),
                ),
            ],
            default => unserialize(\sprintf(
                'O:%d:"%s":3:{s:7:"seconds";i:%d;s:11:"nanoseconds";i:%d;s:8:"negative";b:%d;}',
                \strlen($class),
                $class,
                $a[0],
                $a[1],
                $a[2] ? 1 : 0,
            )),
        };
    }

    private static function pieces(Randomizer $random): string
    {
        $string = '';

        for ($pieces = $random->getInt(0, 8); $pieces > 0; --$pieces) {
            $string .= self::ISO_PIECES[$random->getInt(0, \count(self::ISO_PIECES) - 1)];
        }

        return $string;
    }

    /**
     * A period in the order of the grammar, often with a date, recurrences or whitespace around it,
     * then sometimes broken by a few random pieces: purely random pieces rarely form a period.
     */
    private static function period(Randomizer $random): string
    {
        $part = static function (string $designator) use ($random): string {
            if ($random->getInt(0, 2) === 0) {
                return '';
            }

            $number = match ($random->getInt(0, 4)) {
                0 => '0',
                1 => (string) $random->getInt(1, 99),
                2 => '999999999999',
                3 => '9999999999999',
                // Up to a second above the range, on 32-bit PHP to its end, the end of int.
                default => (string) $random->getInt(0, \PHP_INT_SIZE === 4 ? PHP_INT_MAX : 9_223_372_036),
            };

            return $number . $designator;
        };

        $period = 'P' . $part('Y') . $part('M') . $part('W') . $part('D');

        if ($random->getInt(0, 3) > 0) {
            $period .= 'T' . $part('H') . $part('M') . $part('S');
        }

        $string = match ($random->getInt(0, 5)) {
            0 => 'R5/' . $period,
            1 => '2008-01-01T00:00:00Z/' . $period,
            2 => $period . ' ' . $period,
            3 => " \t{$period}\n",
            default => $period,
        };

        for ($mutations = $random->getInt(0, 3) - 1; $mutations > 0; --$mutations) {
            $offset = $random->getInt(0, \strlen($string));
            $length = $random->getInt(0, 1);
            $string = substr_replace($string, self::pieces($random), $offset, $length);
        }

        return $string;
    }

    private static function int(Randomizer $random): int
    {
        return match ($random->getInt(0, 3)) {
            0 => $random->getInt(0, 2000),
            1 => $random->getInt(0, PHP_INT_MAX),
            default => \PHP_INT_SIZE === 4
                ? self::EDGE_INTS_OF_32_BITS[$random->getInt(0, \count(self::EDGE_INTS_OF_32_BITS) - 1)]
                : self::EDGE_INTS[$random->getInt(0, \count(self::EDGE_INTS) - 1)],
        };
    }

    /**
     * @param class-string<Duration> $class
     * @param array{int, int, bool} $parts
     * @return Duration or the copy of the polyfill, hence no native return type
     */
    private static function create(string $class, array $parts): object
    {
        [$seconds, $nanoseconds, $negative] = $parts;
        $duration = $class::fromSeconds($seconds, $nanoseconds);

        return $negative ? $duration->negate() : $duration;
    }

    /**
     * @return class-string<Duration> the copy has the API of Duration, not its type
     */
    private static function polyfill(): string
    {
        if (!isNative()) {
            throw new SkipTest('Compares the polyfill with Time\Duration of PHP 8.6.');
        }

        return loadPolyfillCopy();
    }
}
