<?php

declare(strict_types=1);

namespace Thesis\Async;

use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;
use function Thesis\Async\Tests\thrown;

#[Test]
final class EachAndMapTest
{
    public function eachCallsFnForEveryItemWithKey(): void
    {
        $calls = [];

        each(['a' => 1, 'b' => 2, 'c' => 3], static function (int $item, string $key) use (&$calls) {
            delay(0.001);
            $calls[] = $key . $item;
        }, 2);

        Assert::array($calls)->sameElementsAs(['a1', 'b2', 'c3']);
    }

    /**
     * @param positive-int $concurrency
     */
    #[DataSet([1])]
    #[DataSet([3])]
    #[DataSet([20])]
    public function eachRunsExactlyConcurrencyTasksAtATime(int $concurrency): void
    {
        $running = 0;
        $peak = 0;

        each(range(1, 10), static function () use (&$running, &$peak) {
            $peak = max($peak, ++$running);
            delay(0.001);
            --$running;
        }, $concurrency);

        Assert::same($peak, min($concurrency, 10));
    }

    public function eachIteratesInCallingFiberSoGeneratorMaySuspend(): void
    {
        $pages = (static function () {
            foreach ([1, 2, 3] as $page) {
                delay(0.001); // fetch the next page
                yield $page;
            }
        })();
        $processed = [];

        each($pages, static function (int $page) use (&$processed) {
            delay(0.002);
            $processed[] = $page;
        }, 2);

        Assert::array($processed)->sameElementsAs([1, 2, 3]);
    }

    public function eachOfNothingDoesNothing(): void
    {
        $called = false;

        each([], static function () use (&$called) {
            $called = true;
        }, 1);

        Assert::false($called);
    }

    public function eachFailsWithFirstFailureAndStopsPullingItems(): void
    {
        $pulled = [];
        $events = [];

        $exception = thrown(static function () use (&$pulled, &$events) {
            each(self::recordPulls(range(1, 10), $pulled), static function (int $item) use (&$events) {
                $events[] = "{$item} started";

                try {
                    match ($item) {
                        1 => null,     // frees its slot for item 3 right away
                        2 => delay(1), // occupies the other slot until the failure
                        default => throw new \RuntimeException("item {$item} failed"),
                    };
                } catch (Cancelled $cancelled) {
                    $events[] = "{$item} cancelled";

                    throw $cancelled;
                }
            }, 2);
        });

        Assert::instanceOf($exception, \RuntimeException::class);
        Assert::same($exception->getMessage(), 'item 3 failed');
        Assert::same($pulled, [1, 2, 3]);
        Assert::same($events, ['1 started', '2 started', '3 started', '2 cancelled']);
    }

    public function eachWithConcurrencyOneStopsPullingAfterFailure(): void
    {
        $pulled = [];

        $exception = thrown(static function () use (&$pulled) {
            each(self::recordPulls(range(1, 5), $pulled), static function (int $item) {
                throw new \RuntimeException("item {$item} failed");
            }, 1);
        });

        Assert::instanceOf($exception, \RuntimeException::class);
        Assert::same($exception->getMessage(), 'item 1 failed');
        Assert::same($pulled, [1]);
    }

    public function eachPullsNextItemOnlyWhenSlotIsFree(): void
    {
        $events = [];
        $items = (static function () use (&$events) {
            foreach ([1, 2, 3] as $item) {
                $events[] = "{$item} pulled";

                yield $item;
            }
        })();

        each($items, static function (int $item) use (&$events) {
            if ($item === 2) {
                delay(0.001);
            }

            $events[] = "{$item} done";
        }, 2);

        Assert::same($events, ['1 pulled', '2 pulled', '1 done', '3 pulled', '3 done', '2 done']);
    }

    /**
     * @param list<int> $items
     * @param list<int> $pulled
     * @return \Generator<int, int>
     */
    private static function recordPulls(array $items, array &$pulled): \Generator
    {
        foreach ($items as $item) {
            $pulled[] = $item;

            yield $item;
        }
    }

    #[DataSet([0])]
    #[DataSet([-1])]
    public function eachRejectsNonPositiveConcurrency(int $concurrency): void
    {
        Expect::exception(\ValueError::class)->withMessage(\sprintf('Concurrency must be positive, got %d.', $concurrency));

        /** @phpstan-ignore argument.type */
        each([1], static fn() => null, $concurrency);
    }

    public function eachIsCancellable(): void
    {
        Expect::exception(TimedOut::class);

        timeout(0.001, static fn() => each([1, 2], static fn() => delay(1), 2));
    }

    public function mapReturnsResultsWithKeysInInputOrder(): void
    {
        $results = map(['x' => 3, 'y' => 1, 'z' => 2], static function (int $ms, string $key) {
            delay($ms / 1_000);

            return $key . $ms;
        }, 3);

        Assert::same($results, ['x' => 'x3', 'y' => 'y1', 'z' => 'z2']);
    }

    public function mapOfNothingIsEmpty(): void
    {
        Assert::same(map([], static fn() => null, 1), []);
    }

    public function mapUnderExpiredDeadlineDoesNotReturnPartialResult(): void
    {
        Expect::exception(TimedOut::class);

        timeout(0.001, static fn() => map([1, 2], static fn() => delay(1), 2));
    }
}
