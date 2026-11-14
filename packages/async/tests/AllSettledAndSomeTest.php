<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use function Thesis\Async\Tests\elapsed;
use function Thesis\Async\Tests\never;
use function Thesis\Async\Tests\thrown;

#[Test]
final class AllSettledAndSomeTest
{
    public function allSettledReturnsResultsAndFailuresWithKeysInInputOrder(): void
    {
        $first = new \RuntimeException('first');
        $second = new \LogicException('second');

        [$results, $errors] = allSettled([
            'slow' => static function () {
                delay(3);

                return 1;
            },
            'late failure' => static function () use ($second) {
                delay(2);

                throw $second;
            },
            7 => static fn() => 2,
            'early failure' => static fn() => throw $first,
            'null' => static fn() => null,
        ]);

        Assert::same($errors, ['late failure' => $second, 'early failure' => $first]);
        Assert::same($results, ['slow' => 1, 7 => 2, 'null' => null]);
    }

    public function allSettledOfNothingIsEmpty(): void
    {
        /** @var array<never, callable(): mixed> */
        $nothing = [];

        Assert::same(allSettled($nothing), [[], []]);
    }

    public function allSettledFailureCancelsNothing(): void
    {
        $events = [];

        [, $errors] = allSettled([
            static function () {
                throw new \RuntimeException('failed');
            },
            static function () use (&$events) {
                delay(2);
                $events[] = 'other finished';
            },
        ]);

        Assert::same(\count($errors), 1);
        Assert::same($events, ['other finished']);
    }

    public function allSettledFailureIsNotSuppressed(): void
    {
        $first = new \RuntimeException('first');
        $second = new \LogicException('second');

        [, $errors] = allSettled([
            static fn() => throw $first,
            static fn() => throw $second,
        ]);

        Assert::same($errors, [$first, $second]);
        Assert::same(suppressed($first), []);
    }

    public function allSettledReturnsThrowableResultAsResult(): void
    {
        $value = new \RuntimeException('a value, not a failure');

        Assert::same(allSettled([static fn() => $value]), [[$value], []]);
    }

    public function allSettledIsCancellable(): void
    {
        Expect::exception(TimedOut::class);

        timeout(1, static fn() => allSettled([never(...)]));
    }

    public function allSettledThrowsCancelledEvenIfTaskTurnsItIntoFailure(): void
    {
        Expect::exception(TimedOut::class);

        timeout(1, static fn() => allSettled([
            static function () {
                try {
                    never();
                } catch (Cancelled) {
                    throw new \RuntimeException('connection reset');
                }
            },
        ]));
    }

    public function allSettledWithTimeoutPerTaskKeepsWhatIsReady(): void
    {
        [$results, $errors] = allSettled([
            'fast' => static fn() => timeout(50, static fn() => 'fast'),
            'slow' => static fn() => timeout(1, never(...)),
        ]);

        Assert::same($results, ['fast' => 'fast']);
        Assert::same(array_keys($errors), ['slow']);
        Assert::instanceOf($errors['slow'] ?? null, TimedOut::class);
    }

    public function someReturnsFirstSuccessesInOrderTheySucceeded(): void
    {
        $results = some(2, [
            'slow' => static function () {
                delay(20);

                return 'slow';
            },
            'medium' => static function () {
                delay(3);

                return 'medium';
            },
            'fast' => static function () {
                delay(1);

                return 'fast';
            },
        ]);

        Assert::same($results, ['fast' => 'fast', 'medium' => 'medium']);
    }

    public function someOfAllIsAllInOrderTheySucceeded(): void
    {
        $results = some(2, [
            'a' => static function () {
                delay(2);

                return 'a';
            },
            'b' => static fn() => 'b',
        ]);

        Assert::same($results, ['b' => 'b', 'a' => 'a']);
    }

    public function someCancelsAndJoinsTheRest(): void
    {
        $loserCleanedUp = false;

        some(1, [
            static function () {
                delay(1);

                return 'winner';
            },
            static function () use (&$loserCleanedUp) {
                try {
                    never();
                } finally {
                    shield(static fn() => delay(3));
                    $loserCleanedUp = true;
                }
            },
        ]);

        Assert::true($loserCleanedUp);
    }

    public function someReachedSynchronouslyDoesNotStartTheRest(): void
    {
        $started = false;

        some(2, [
            static fn() => 'a',
            static fn() => 'b',
            static function () use (&$started) {
                $started = true;
            },
        ]);

        Assert::false($started);
    }

    public function someIgnoresFailuresWhileEnoughCanSucceed(): void
    {
        $results = some(2, [
            'down' => static fn() => throw new \RuntimeException('down'),
            'a' => static function () {
                delay(1);

                return 'a';
            },
            'b' => static function () {
                delay(2);

                return 'b';
            },
        ]);

        Assert::same($results, ['a' => 'a', 'b' => 'b']);
    }

    public function someIgnoresFailuresAfterSuccess(): void
    {
        $results = some(1, [
            static function () {
                delay(1);

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

        Assert::same($results, ['winner']);
    }

    public function someIgnoresSuccessesAfterFailure(): void
    {
        $returned = false;

        $exception = thrown(static function () use (&$returned) {
            some(2, [
                static function () {
                    delay(1);

                    throw new \RuntimeException('a');
                },
                static function () {
                    delay(1);

                    throw new \RuntimeException('b');
                },
                static function () use (&$returned) {
                    try {
                        never();
                    } catch (Cancelled) {
                    }

                    $returned = true;

                    return 'too late';
                },
            ]);
        });

        Assert::true($returned); // the third task has started, and it succeeds once the failure is decided
        Assert::same($exception->getMessage(), 'a');
        Assert::same(array_map(static fn(\Throwable $error) => $error->getMessage(), suppressed($exception)), ['b']);
    }

    public function someIgnoresSuccessesAfterEnoughSucceeded(): void
    {
        $returned = false;

        $results = some(1, [
            'late' => static function () use (&$returned) {
                try {
                    never();
                } catch (Cancelled) {
                }

                $returned = true;

                return 'too late';
            },
            'winner' => static function () {
                delay(1);

                return 'winner';
            },
        ]);

        Assert::true($returned);
        Assert::same($results, ['winner' => 'winner']);
    }

    public function someFailsAsSoonAsEnoughSuccessesAreImpossible(): void
    {
        $first = new \RuntimeException('first');
        $second = new \LogicException('second');

        $exception = null;
        $elapsed = elapsed(static function () use ($first, $second, &$exception) {
            $exception = thrown(static fn() => some(2, [
                static function () use ($first) {
                    delay(1);

                    throw $first;
                },
                static function () use ($second) {
                    delay(2);

                    throw $second;
                },
                never(...),
            ]));
        });

        Assert::same($exception, $first);
        Assert::same(suppressed($first), [$second]);
        Assert::same($elapsed, 2.0); // the third task is not waited for
    }

    public function someSuppressesFailuresOfTasksBeingCancelled(): void
    {
        $first = new \RuntimeException('first');
        $reset = new \LogicException('connection reset');

        $exception = thrown(static fn() => some(2, [
            static function () use ($first) {
                delay(1);

                throw $first;
            },
            static function () use ($reset) {
                try {
                    never();
                } catch (Cancelled) {
                    throw $reset;
                }
            },
        ]));

        Assert::same($exception, $first);
        Assert::same(suppressed($first), [$reset]);
    }

    public function someIsCancellable(): void
    {
        Expect::exception(TimedOut::class);

        timeout(1, static fn() => some(1, [never(...), never(...)]));
    }

    public function someOfMoreThanThereAreIsRejected(): void
    {
        Expect::exception(\ValueError::class)->withMessage('some() needs at least 3 tasks, got 2.');

        some(3, [static fn() => 1, static fn() => 2]);
    }

    public function someOfNothingIsRejected(): void
    {
        Expect::exception(\ValueError::class)->withMessage('some() needs at least 1 task, got 0.');

        /** @var array<never, callable(): mixed> */
        $nothing = [];

        some(1, $nothing);
    }
}
