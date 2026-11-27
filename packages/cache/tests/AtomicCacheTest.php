<?php

declare(strict_types=1);

namespace Thesis\Cache;

use Testo\Assert;
use Testo\Test;
use Thesis\Async\Group;
use Thesis\Async\Instant;
use Thesis\Async\LocalKeyedMutex;
use Thesis\Async\TimedOut;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\shield;
use function Thesis\Async\timeout;
use function Thesis\Cache\Tests\thrown;
use function Thesis\Time\ms;
use function Thesis\Time\sec;
use const Thesis\Time\ZERO;

#[Test]
final class AtomicCacheTest extends CacheContract
{
    protected function createCache(): Cache
    {
        /** @var AtomicCache<string> */
        return new AtomicCache(new LocalCache());
    }

    public function computesMissingValueAndStoresIt(): void
    {
        $inner = new LocalCache();
        $cache = new AtomicCache($inner);

        $value = $cache->getOrCompute('a', static fn(string $key) => "value of {$key}", sec(10));

        Assert::same($value, 'value of a');
        Assert::same($inner->get('a'), 'value of a');
    }

    public function storesWithTtl(): void
    {
        $cache = new AtomicCache(new LocalCache());

        $cache->getOrCompute('a', static fn() => 1, sec(10));
        $beforeExpiry = $cache->get('a');
        delay(sec(10));

        Assert::same($beforeExpiry, 1);
        Assert::null($cache->get('a'));
    }

    public function hitDoesNotCompute(): void
    {
        $cache = new AtomicCache(new LocalCache());
        $cache->set('a', 'stored');

        $value = $cache->getOrCompute('a', static fn() => Assert::fail('Computed on a hit.'));

        Assert::same($value, 'stored');
    }

    public function storedNullIsHit(): void
    {
        $cache = new AtomicCache(new LocalCache());
        $cache->set('a', null);

        $value = $cache->getOrCompute('a', static fn() => Assert::fail('Computed on a hit.'));

        Assert::null($value);
    }

    public function hitTakesNoLock(): void
    {
        $mutex = new LocalKeyedMutex();
        $cache = new AtomicCache(new LocalCache(), $mutex);
        $cache->set('a', 'stored');
        $lock = $mutex->acquire('a');

        $value = timeout(sec(1), static fn() => $cache->getOrCompute('a', static fn() => 'computed'));
        $read = timeout(sec(1), static fn() => $cache->get('a'));

        Assert::same($value, 'stored');
        Assert::same($read, 'stored');
        $lock->release();
    }

    public function concurrentCallersOfKeyComputeOnce(): void
    {
        $cache = new AtomicCache(new LocalCache());
        $computations = 0;
        $compute = static function () use (&$computations) {
            delay(sec(1));

            return ++$computations;
        };

        $values = group(static function (Group $group) use ($cache, $compute) {
            $tasks = [];

            for ($i = 0; $i < 5; ++$i) {
                $tasks[] = $group->spawn(static fn() => $cache->getOrCompute('a', $compute));
            }

            return array_map(static fn($task) => $task->await(), $tasks);
        });

        Assert::same($computations, 1);
        Assert::same($values, [1, 1, 1, 1, 1]);
    }

    public function keysComputeConcurrently(): void
    {
        $cache = new AtomicCache(new LocalCache());

        $elapsed = Instant::elapsed(static fn() => group(static function (Group $group) use ($cache) {
            foreach (['a', 'b'] as $key) {
                $group->spawn(static fn() => $cache->getOrCompute($key, static fn() => delay(sec(1))));
            }
        }));

        Assert::equals($elapsed, sec(1));
    }

    public function failureStoresNothingAndNextCallerComputes(): void
    {
        $cache = new AtomicCache(new LocalCache());
        $failure = new \RuntimeException('Failed to compute.');

        $exception = thrown(static fn() => $cache->getOrCompute('a', static fn() => throw $failure));
        $missing = $cache->get('a', 'missing');
        $value = $cache->getOrCompute('a', static fn() => 'computed');

        Assert::same($exception, $failure);
        Assert::same($missing, 'missing');
        Assert::same($value, 'computed');
    }

    public function cancelledComputationLetsNextWaiterCompute(): void
    {
        $cache = new AtomicCache(new LocalCache());

        [$first, $second] = group(static function (Group $group) use ($cache) {
            $first = $group->spawn(static fn() => thrown(static fn() => timeout(
                duration: sec(1),
                body: static fn() => $cache->getOrCompute('a', static fn() => delay(sec(10))),
            )));
            $second = $group->spawn(static function () use ($cache) {
                delay(ms(500));

                return $cache->getOrCompute('a', static fn() => 'second');
            });

            return [$first->await(), $second->await()];
        });

        Assert::instanceOf($first, TimedOut::class);
        Assert::same($second, 'second');
        Assert::same($cache->get('a'), 'second');
    }

    public function deleteWaitsForComputationAndWins(): void
    {
        $cache = new AtomicCache(new LocalCache());

        $deletedAfter = group(static function (Group $group) use ($cache) {
            $start = Instant::now();
            $group->spawn(static fn() => $cache->getOrCompute('a', static function () {
                delay(sec(1));

                return 'stale';
            }));
            $delete = $group->spawn(static function () use ($cache, $start) {
                delay(ms(500));
                $cache->delete('a');

                return Instant::now()->since($start);
            });

            return $delete->await();
        });

        Assert::equals($deletedAfter, sec(1));
        Assert::same($cache->get('a', 'missing'), 'missing');
    }

    public function setWaitsForComputationAndWins(): void
    {
        $cache = new AtomicCache(new LocalCache());

        group(static function (Group $group) use ($cache) {
            $group->spawn(static fn() => $cache->getOrCompute('a', static function () {
                delay(sec(1));

                return 'stale';
            }));
            $group->spawn(static function () use ($cache) {
                delay(ms(500));
                $cache->set('a', 'fresh');
            });
        });

        Assert::same($cache->get('a'), 'fresh');
    }

    public function computationMaySetAndDeleteItsKey(): void
    {
        $cache = new AtomicCache(new LocalCache());

        $value = timeout(sec(1), static fn() => $cache->getOrCompute('a', static function () use ($cache) {
            $cache->set('a', 'inner');
            $cache->delete('a');

            return 'outer';
        }));

        Assert::same($value, 'outer');
        Assert::same($cache->get('a'), 'outer');
    }

    public function cachesSharingMutexComputeOnce(): void
    {
        $inner = new LocalCache();
        $mutex = new LocalKeyedMutex();
        $first = new AtomicCache($inner, $mutex);
        $second = new AtomicCache($inner, $mutex);
        $computations = 0;
        $compute = static function () use (&$computations) {
            delay(sec(1));

            return ++$computations;
        };

        group(static function (Group $group) use ($first, $second, $compute) {
            $group->spawn(static fn() => $first->getOrCompute('a', $compute));
            $group->spawn(static fn() => $second->getOrCompute('a', $compute));
        });

        Assert::same($computations, 1);
    }

    public function waiterCancelledWhileWaitingLeavesOthersUnaffected(): void
    {
        $cache = new AtomicCache(new LocalCache());
        $compute = static function () {
            delay(sec(1));

            return 'first';
        };

        $results = group(static function (Group $group) use ($cache, $compute) {
            $first = $group->spawn(static fn() => $cache->getOrCompute('a', $compute));
            $cancelled = $group->spawn(static fn() => thrown(static fn() => timeout(
                duration: ms(500),
                body: static fn() => $cache->getOrCompute('a', static fn() => 'cancelled'),
            )));
            $third = $group->spawn(static fn() => $cache->getOrCompute('a', static fn() => 'third'));

            return [$first->await(), $cancelled->await()::class, $third->await()];
        });

        Assert::same($results, ['first', TimedOut::class, 'first']);
    }

    public function waiterComputesAfterFailureOfConcurrentComputation(): void
    {
        $cache = new AtomicCache(new LocalCache());

        [$failure, $value] = group(static function (Group $group) use ($cache) {
            $failed = $group->spawn(static fn() => thrown(static fn() => $cache->getOrCompute('a', static function () {
                delay(sec(1));

                throw new \RuntimeException('Failed to compute.');
            })));
            $waiter = $group->spawn(static fn() => $cache->getOrCompute('a', static fn() => 'waiter'));

            return [$failed->await(), $waiter->await()];
        });

        Assert::instanceOf($failure, \RuntimeException::class);
        Assert::same($value, 'waiter');
    }

    public function computationMayComputeOtherKeys(): void
    {
        /** @var AtomicCache<string> */
        $cache = new AtomicCache(new LocalCache());

        $value = $cache->getOrCompute('a', static fn() => 'a with ' . $cache->getOrCompute('b', static fn() => 'b'));

        Assert::same($value, 'a with b');
        Assert::same($cache->get('b'), 'b');
    }

    public function resultOfComputationReplacesWhatItStoredUnderItsKey(): void
    {
        $cache = new AtomicCache(new LocalCache());

        $value = timeout(sec(1), static fn() => $cache->getOrCompute('a', static function () use ($cache) {
            $cache->getOrCompute('a', static fn() => 'inner');

            return 'outer';
        }));

        Assert::same($value, 'outer');
        Assert::same($cache->get('a'), 'outer');
    }

    public function childTaskOfComputationWaitsForLockOfItsKey(): void
    {
        $cache = new AtomicCache(new LocalCache());

        $exception = thrown(static fn() => timeout(
            duration: sec(1),
            body: static fn() => $cache->getOrCompute('a', static fn() => group(
                static fn(Group $group) => $group->spawn(static fn() => $cache->delete('a'))->await(),
            )),
        ));

        Assert::instanceOf($exception, TimedOut::class);
    }

    public function deleteCancelledWhileWaitingIsNotApplied(): void
    {
        $cache = new AtomicCache(new LocalCache());

        $deleteFailure = group(static function (Group $group) use ($cache) {
            $group->spawn(static fn() => $cache->getOrCompute('a', static function () {
                delay(sec(1));

                return 'stale';
            }));
            $delete = $group->spawn(static function () use ($cache) {
                delay(ms(250));

                return thrown(static fn() => timeout(ms(500), static fn() => $cache->delete('a')));
            });

            return $delete->await();
        });

        Assert::instanceOf($deleteFailure, TimedOut::class);
        Assert::same($cache->get('a'), 'stale');
    }

    public function shieldedDeleteWinsOverCancellation(): void
    {
        $cache = new AtomicCache(new LocalCache());

        group(static function (Group $group) use ($cache) {
            $group->spawn(static fn() => $cache->getOrCompute('a', static function () {
                delay(sec(1));

                return 'stale';
            }));
            $group->spawn(static function () use ($cache) {
                delay(ms(250));
                thrown(static fn() => timeout(ms(500), static function () use ($cache) {
                    shield(static fn() => $cache->delete('a'));
                    delay(sec(10));
                }));
            });
        });

        Assert::same($cache->get('a', 'missing'), 'missing');
    }

    public function nonPositiveTtlStoresNothingAndCallersComputeInTurn(): void
    {
        $cache = new AtomicCache(new LocalCache());
        $computations = 0;
        $compute = static function () use (&$computations) {
            delay(sec(1));

            return ++$computations;
        };

        $elapsed = Instant::elapsed(static fn() => group(static function (Group $group) use ($cache, $compute) {
            for ($i = 0; $i < 3; ++$i) {
                $group->spawn(static fn() => $cache->getOrCompute('a', $compute, ZERO));
            }
        }));

        Assert::same($computations, 3);
        Assert::equals($elapsed, sec(3));
        Assert::same($cache->get('a', 'missing'), 'missing');
    }
}
