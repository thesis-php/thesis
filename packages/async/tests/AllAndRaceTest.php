<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use function Thesis\Async\Tests\thrown;

#[Test]
final class AllAndRaceTest
{
    public function allReturnsResultsWithKeysInInputOrder(): void
    {
        $results = all([
            'slow' => static function () {
                delay(0.003);

                return 1;
            },
            7 => static fn() => 2,
            'medium' => static function () {
                delay(0.001);

                return 3;
            },
        ]);

        Assert::same($results, ['slow' => 1, 7 => 2, 'medium' => 3]);
    }

    public function allOfNothingIsEmpty(): void
    {
        /** @var array<never, callable(): mixed> */
        $nothing = [];

        Assert::same(all($nothing), []);
    }

    public function allRunsConcurrently(): void
    {
        $events = [];

        all([
            static function () use (&$events) {
                $events[] = 'a started';
                delay(0.02);
                $events[] = 'a finished';
            },
            static function () use (&$events) {
                $events[] = 'b started';
                delay(0.001);
                $events[] = 'b finished';
            },
        ]);

        Assert::same($events, ['a started', 'b started', 'b finished', 'a finished']);
    }

    public function allFailsWithFirstFailureAndCancelsTheRest(): void
    {
        $events = [];

        $exception = thrown(static function () use (&$events) {
            all([
                static function () use (&$events) {
                    try {
                        delay(1);
                    } catch (Cancelled $cancelled) {
                        $events[] = 'other cancelled';

                        throw $cancelled;
                    }
                },
                static function () {
                    delay(0.001);

                    throw new \RuntimeException('failed');
                },
            ]);
        });

        Assert::instanceOf($exception, \RuntimeException::class);
        Assert::same($events, ['other cancelled']);
    }

    public function raceReturnsFirstSuccess(): void
    {
        $winner = race([
            static function () {
                delay(0.02);

                return 'slow';
            },
            static function () {
                delay(0.001);

                return 'fast';
            },
        ]);

        Assert::same($winner, 'fast');
    }

    public function raceWithSingleContenderReturnsItsResult(): void
    {
        Assert::same(race([static fn() => 'only']), 'only');
    }

    public function raceMayBeWonByNull(): void
    {
        $winner = race([
            static fn() => null,
            static function () {
                delay(1);

                return 'late';
            },
        ]);

        Assert::null($winner);
    }

    public function raceCancelsAndJoinsLosers(): void
    {
        $loserCleanedUp = false;

        race([
            static function () {
                delay(0.001);

                return 'winner';
            },
            static function () use (&$loserCleanedUp) {
                try {
                    delay(1);
                } finally {
                    shield(static fn() => delay(0.003));
                    $loserCleanedUp = true;
                }
            },
        ]);

        Assert::true($loserCleanedUp);
    }

    public function raceWonSynchronouslyDoesNotStartTheRest(): void
    {
        $started = false;

        race([
            static fn() => 'cached',
            static function () use (&$started) {
                $started = true;
            },
        ]);

        Assert::false($started);
    }

    public function raceIgnoresFailureWhileAnotherCanWin(): void
    {
        $winner = race([
            static function () {
                throw new \RuntimeException('primary is down');
            },
            static function () {
                delay(0.001);

                return 'replica';
            },
        ]);

        Assert::same($winner, 'replica');
    }

    public function raceIgnoresLoserFailingOnCancellation(): void
    {
        $winner = race([
            static function () {
                delay(0.001);

                return 'winner';
            },
            static function () {
                try {
                    delay(1);
                } catch (Cancelled) {
                    throw new \RuntimeException('connection reset');
                }
            },
        ]);

        Assert::same($winner, 'winner');
    }

    public function raceIgnoresLoserFinishingAfterWinner(): void
    {
        $winner = race([
            static function () {
                delay(0.001);

                return 'first';
            },
            static function () {
                delay(0.001);

                return 'second';
            },
        ]);

        Assert::same($winner, 'first');
    }

    public function raceRethrowsFirstFailureIfEveryoneFailed(): void
    {
        Expect::exception(\RuntimeException::class)->withMessage('first');

        race([
            static function () {
                delay(0.001);

                throw new \RuntimeException('first');
            },
            static function () {
                delay(0.02);

                throw new \LogicException('second');
            },
        ]);
    }

    public function raceSuppressesOtherFailuresIfEveryoneFailed(): void
    {
        $first = new \RuntimeException('first');
        $second = new \LogicException('second');

        $exception = thrown(static fn() => race([
            static fn() => throw $first,
            static function () use ($second) {
                delay(0.001);

                throw $second;
            },
        ]));

        Assert::same($exception, $first);
        Assert::same(suppressed($first), [$second]);
    }

    public function raceIsCancellable(): void
    {
        Expect::exception(TimedOut::class);

        timeout(0.001, static fn() => race([static fn() => delay(1), static fn() => delay(1)]));
    }

    public function raceAcceptsAnyKeys(): void
    {
        $winner = race([
            'primary' => static function () {
                delay(1);

                return 'primary';
            },
            'replica' => static fn() => 'replica',
        ]);

        Assert::same($winner, 'replica');
    }
}
