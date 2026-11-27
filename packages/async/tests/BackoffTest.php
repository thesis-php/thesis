<?php

declare(strict_types=1);

namespace Thesis\Async;

use Random\Engine\Mt19937;
use Random\Randomizer;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use function Thesis\Time\ms;
use function Thesis\Time\ns;
use function Thesis\Time\sec;
use function Thesis\Time\toNs;
use const Thesis\Time\ZERO;

#[Test]
final class BackoffTest
{
    public function doublesUpToTheMaximum(): void
    {
        $backoff = new Backoff(initial: ms(100), max: ms(500), jitter: false);

        Assert::same(self::delays($backoff, 5), [100, 200, 400, 500, 500]);
    }

    public function doublesUpToAnOddMaximum(): void
    {
        $backoff = new Backoff(initial: ns(1), max: ns(3), jitter: false);

        Assert::equals([$backoff->next(), $backoff->next(), $backoff->next()], [ns(1), ns(2), ns(3)]);
    }

    public function startsAgainAfterReset(): void
    {
        $backoff = new Backoff(initial: ms(100), max: sec(1), jitter: false);
        $backoff->next();
        $backoff->next();

        $backoff->reset();

        Assert::same(self::delays($backoff, 1), [100]);
    }

    public function jitterTakesBetweenHalfAndAllOfTheDelay(): void
    {
        $backoff = new Backoff(initial: ms(100), max: ms(400), randomizer: new Randomizer(new Mt19937(42)));
        $bounds = [[50, 100], [100, 200], [200, 400], [200, 400], [200, 400]];

        foreach ($bounds as [$min, $max]) {
            $delay = toNs($backoff->next()) / 1_000_000;

            Assert::true($delay >= $min && $delay <= $max, "{$delay} ms is not within [{$min}, {$max}]");
        }
    }

    public function jitterSpreadsTheDelays(): void
    {
        $backoff = new Backoff(initial: sec(1), max: sec(1), randomizer: new Randomizer(new Mt19937(42)));

        Assert::true(\count(array_unique(self::delays($backoff, 10))) > 1);
    }

    public function jitterTakesItsRandomnessFromTheRandomizer(): void
    {
        $first = new Backoff(initial: sec(1), max: sec(1), randomizer: new Randomizer(new Mt19937(42)));
        $second = new Backoff(initial: sec(1), max: sec(1), randomizer: new Randomizer(new Mt19937(42)));

        Assert::same(self::delays($first, 10), self::delays($second, 10));
    }

    public function doesNotOverflowWithAHugeMaximum(): void
    {
        $max = sec(9_000_000_000);
        $backoff = new Backoff(initial: sec(1), max: $max, jitter: false);

        for ($i = 0; $i < 100; ++$i) {
            $backoff->next();
        }

        Assert::equals($backoff->next(), $max);
    }

    public function rejectsANonPositiveInitialDelay(): void
    {
        Expect::exception(\ValueError::class)->withMessage('The initial delay of a backoff must be positive.');

        new Backoff(initial: ZERO, max: sec(1));
    }

    public function rejectsAMaximumBelowTheInitialDelay(): void
    {
        Expect
            ::exception(\ValueError::class)
            ->withMessage('The maximum delay of a backoff must not be less than the initial one.')
        ;

        new Backoff(initial: sec(2), max: sec(1));
    }

    /**
     * @param positive-int $count
     * @return list<int> milliseconds
     */
    private static function delays(Backoff $backoff, int $count): array
    {
        $delays = [];

        for ($i = 0; $i < $count; ++$i) {
            $delays[] = intdiv(toNs($backoff->next()), 1_000_000);
        }

        return $delays;
    }
}
