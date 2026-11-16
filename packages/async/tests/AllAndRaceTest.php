<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use function Thesis\Async\Tests\never;
use function Thesis\Async\Tests\thrown;
use function Thesis\Async\Tests\untilCancelled;
use function Thesis\Time\sec;

#[Test]
final class AllAndRaceTest
{
    public function allReturnsResultsWithKeysInInputOrder(): void
    {
        $results = all([
            'slow' => static function () {
                delay(sec(3));

                return 1;
            },
            7 => static fn() => 2,
            'medium' => static function () {
                delay(sec(1));

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
                delay(sec(20));
                $events[] = 'a finished';
            },
            static function () use (&$events) {
                $events[] = 'b started';
                delay(sec(1));
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
                        never();
                    } catch (Cancelled $cancelled) {
                        $events[] = 'other cancelled';

                        throw $cancelled;
                    }
                },
                static function () {
                    delay(sec(1));

                    throw new \RuntimeException('failed');
                },
            ]);
        });

        Assert::instanceOf($exception, \RuntimeException::class);
        Assert::same($events, ['other cancelled']);
    }

    public function allIsCancellable(): void
    {
        Expect::exception(TimedOut::class);

        timeout(sec(1), static fn() => all([never(...), never(...)]));
    }

    public function raceReturnsFirstSuccess(): void
    {
        $winner = race([
            static function () {
                delay(sec(20));

                return 'slow';
            },
            static function () {
                delay(sec(1));

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
                delay(sec(1));

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
                delay(sec(1));

                return 'winner';
            },
            static function () use (&$loserCleanedUp) {
                try {
                    never();
                } finally {
                    shield(static fn() => delay(sec(3)));
                    $loserCleanedUp = true;
                }
            },
        ]);

        Assert::true($loserCleanedUp);
    }

    public function raceWonIsNotDiscardedByOuterCancellationWhileLosersUnwind(): void
    {
        $result = timeout(sec(2), static fn() => race([
            static function () {
                delay(sec(1));

                return 'winner';
            },
            static function () {
                try {
                    never();
                } finally {
                    shield(static fn() => delay(sec(3)));
                }
            },
        ]));

        Assert::same($result, 'winner');
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
                delay(sec(1));

                return 'replica';
            },
        ]);

        Assert::same($winner, 'replica');
    }

    public function raceIgnoresLoserFailingOnCancellation(): void
    {
        $winner = race([
            static function () {
                delay(sec(1));

                return 'winner';
            },
            static function () {
                try {
                    never();
                } catch (Cancelled) {
                    throw new \RuntimeException('connection reset');
                }
            },
        ]);

        Assert::same($winner, 'winner');
    }

    public function raceIgnoresLoserFinishingAfterWinner(): void
    {
        $loserReturned = false;

        $winner = race([
            static function () use (&$loserReturned) {
                untilCancelled();

                $loserReturned = true;

                return 'loser';
            },
            static function () {
                delay(sec(1));

                return 'winner';
            },
        ]);

        Assert::true($loserReturned);
        Assert::same($winner, 'winner');
    }

    public function raceOfNothingIsRejected(): void
    {
        Expect::exception(\ValueError::class)->withMessage('race() needs at least 1 task, got 0.');

        /** @phpstan-ignore argument.type, argument.templateType */
        race([]);
    }

    public function raceRethrowsFirstFailureIfEveryoneFailed(): void
    {
        Expect::exception(\RuntimeException::class)->withMessage('first');

        race([
            static function () {
                delay(sec(1));

                throw new \RuntimeException('first');
            },
            static function () {
                delay(sec(20));

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
                delay(sec(1));

                throw $second;
            },
        ]));

        Assert::same($exception, $first);
        Assert::same(suppressed($first), [$second]);
    }

    public function raceIsCancellable(): void
    {
        Expect::exception(TimedOut::class);

        timeout(sec(1), static fn() => race([never(...), never(...)]));
    }

    public function raceAcceptsAnyKeys(): void
    {
        $winner = race([
            'primary' => static function () {
                delay(sec(1));

                return 'primary';
            },
            'replica' => static fn() => 'replica',
        ]);

        Assert::same($winner, 'replica');
    }
}
