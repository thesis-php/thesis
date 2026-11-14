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

    public function eachIteratesGeneratorThatSuspends(): void
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

        // Both workers are busy until item 1 is done, so item 3 waits for that.
        each($items, static function (int $item) use (&$events) {
            match ($item) {
                1 => delay(0.001),
                2 => delay(0.002),
                default => null,
            };

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

    public function eachProcessesItemsInConcurrencyFibers(): void
    {
        $fibers = new \SplObjectStorage(); // holds the fibers, so a dead one cannot pass its id to a new one

        each(range(1, 10), static function () use ($fibers) {
            $fibers->offsetSet(\Fiber::getCurrent());
            delay(0.001);
        }, 3);

        Assert::same($fibers->count(), 3);
    }

    public function eachStartsNoMoreFibersThanItemsNeed(): void
    {
        $fibers = new \SplObjectStorage();

        each([1, 2], static function () use ($fibers) {
            $fibers->offsetSet(\Fiber::getCurrent());
            delay(0.001);
        }, 100);

        Assert::same($fibers->count(), 2);
    }

    public function eachProcessesItemsThatDoNotWaitInOneFiber(): void
    {
        $fibers = new \SplObjectStorage();

        each(range(1, 10), static function () use ($fibers) {
            $fibers->offsetSet(\Fiber::getCurrent());
        }, 3);

        Assert::same($fibers->count(), 1);
    }

    public function eachDoesNotResumeGeneratorSuspendedByAnotherWorker(): void
    {
        $pages = (static function () {
            foreach (range(1, 6) as $page) {
                delay(0.001);

                yield $page;
            }
        })();
        $processed = [];

        each($pages, static function (int $page) use (&$processed) {
            $processed[] = $page; // takes no time: every worker is back for the next page while the generator waits
        }, 3);

        Assert::same($processed, range(1, 6));
    }

    public function eachPassesKeysThatAreNotArrayKeys(): void
    {
        $key = new \stdClass();
        $keys = [];

        each((static fn() => yield $key => 'item')(), static function (string $item, object $key) use (&$keys) {
            $keys[] = $key;
        }, 1);

        Assert::same($keys, [$key]);
    }

    public function eachDoesNotPullAfterFailureOfItemThatDoesNotWait(): void
    {
        $pulled = [];

        $exception = thrown(static function () use (&$pulled) {
            each(self::recordPulls(range(1, 5), $pulled), static function (int $item) {
                match ($item) {
                    1 => delay(0.001),
                    default => throw new \RuntimeException("item {$item} failed"),
                };
            }, 3);
        });

        Assert::same($exception->getMessage(), 'item 2 failed');
        Assert::same($pulled, [1, 2]);
    }

    public function eachDoesNotPullAfterFailureWhenFnCatchesCancelled(): void
    {
        $pulled = [];

        $exception = thrown(static function () use (&$pulled) {
            each(self::recordPulls(range(1, 5), $pulled), static function (int $item) {
                if ($item === 2) {
                    throw new \RuntimeException('item 2 failed');
                }

                try {
                    delay(1);
                } catch (Cancelled) {
                    // returns normally, as a sloppy $fn would
                }
            }, 2);
        });

        Assert::same($exception->getMessage(), 'item 2 failed');
        Assert::same($pulled, [1, 2]);
    }

    public function eachStartsNewWorkerOnceEarlierOnesAreBusyAgain(): void
    {
        $running = 0;
        $peak = 0;

        // The first item does not wait: its worker is free again before the rest are taken.
        each(range(0, 3), static function (int $item) use (&$running, &$peak) {
            $peak = max($peak, ++$running);

            if ($item > 0) {
                delay(0.001);
            }

            --$running;
        }, 3);

        Assert::same($peak, 3);
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

    public function mapKeepsInputOrderWhenWorkersTakeItemsOutOfOrder(): void
    {
        $results = map(['a' => 4, 'b' => 1, 'c' => 1, 'd' => 2, 'e' => 1], static function (int $ms, string $key) {
            delay($ms / 1_000);

            return $key;
        }, 2);

        Assert::same($results, ['a' => 'a', 'b' => 'b', 'c' => 'c', 'd' => 'd', 'e' => 'e']);
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
