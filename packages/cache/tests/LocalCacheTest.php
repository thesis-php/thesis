<?php

declare(strict_types=1);

namespace Thesis\Cache;

use Random\Engine\Mt19937;
use Random\Randomizer;
use Testo\Assert;
use Testo\Test;
use Thesis\Async\Group;
use Time\Duration;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Time\hours;
use function Thesis\Time\ms;
use function Thesis\Time\ns;
use function Thesis\Time\sec;
use const Thesis\Time\ZERO;

#[Test]
final class LocalCacheTest extends CacheContract
{
    protected function createCache(): Cache
    {
        /** @var LocalCache<string> */
        return new LocalCache();
    }

    public function storedNullIsToldFromMissByDefault(): void
    {
        $cache = new LocalCache();
        $missing = new \stdClass();

        $cache->set('a', null);

        Assert::null($cache->get('a', $missing));
        Assert::same($cache->get('b', $missing), $missing);
    }

    public function keepsObjectsAsTheyAre(): void
    {
        $cache = new LocalCache();
        $object = new \stdClass();

        $cache->set('a', $object);

        Assert::same($cache->get('a'), $object);
    }

    public function ttlIsExact(): void
    {
        $cache = new LocalCache();
        $cache->set('a', 1, ns(1_000_000_001));

        delay(sec(1));
        $beforeExpiry = $cache->get('a');
        delay(ns(1));

        Assert::same($beforeExpiry, 1);
        Assert::null($cache->get('a'));
    }

    public function entryWithoutTtlOutlivesAnyWait(): void
    {
        $cache = new LocalCache();
        $cache->set('a', 1);

        delay(hours(1000));

        Assert::same($cache->get('a'), 1);
    }

    public function capacityCountsNumericStringKeys(): void
    {
        $cache = new LocalCache(capacity: 2);

        $cache->set('1', 'one');
        $cache->set('2', 'two');
        $cache->set('3', 'three');

        Assert::null($cache->get('1'));
        Assert::same($cache->get('2'), 'two');
        Assert::same($cache->get('3'), 'three');
    }

    public function evictsLeastRecentlyUsedOverCapacity(): void
    {
        $cache = new LocalCache(capacity: 2);
        $cache->set('a', 1);
        $cache->set('b', 2);

        $cache->get('a');
        $cache->set('c', 3);

        Assert::same($cache->get('a'), 1);
        Assert::null($cache->get('b'));
        Assert::same($cache->get('c'), 3);
    }

    public function setOfExistingKeyEvictsNothing(): void
    {
        $cache = new LocalCache(capacity: 2);
        $cache->set('a', 1);
        $cache->set('b', 2);

        $cache->set('a', 3);

        Assert::same($cache->get('a'), 3);
        Assert::same($cache->get('b'), 2);
    }

    public function setOfExistingKeyMakesItMostRecentlyUsed(): void
    {
        $cache = new LocalCache(capacity: 2);
        $cache->set('a', 1);
        $cache->set('b', 2);

        $cache->set('a', 3);
        $cache->set('c', 4);

        Assert::same($cache->get('a'), 3);
        Assert::null($cache->get('b'));
    }

    public function capacityOfOne(): void
    {
        $cache = new LocalCache(capacity: 1);

        $cache->set('a', 1);
        $cache->get('a');
        $cache->set('b', 2);

        Assert::null($cache->get('a'));
        Assert::same($cache->get('b'), 2);
    }

    public function countDropsEntryAtTheEndOfItsTtl(): void
    {
        $cache = new LocalCache();
        $cache->set('a', 1, sec(1));

        delay(sec(1));

        Assert::same(\count($cache), 0);
    }

    public function countsEntries(): void
    {
        $cache = new LocalCache();
        $empty = \count($cache);

        $cache->set('a', 1);
        $cache->set('b', 2);
        $cache->set('a', 3);
        $cache->set('c', 4);
        $cache->delete('c');

        Assert::same($empty, 0);
        Assert::same(\count($cache), 2);
    }

    public function countIsAtMostCapacity(): void
    {
        $cache = new LocalCache(capacity: 2);

        $cache->set('a', 1);
        $cache->set('b', 2);
        $cache->set('c', 3);

        Assert::same(\count($cache), 2);
    }

    public function countSkipsExpiredEntriesThatAreNotRead(): void
    {
        $cache = new LocalCache();
        $cache->set('expiring', 1, sec(1));
        $cache->set('lasting', 2, hours(1));
        $cache->set('forever', 3);

        delay(sec(2));

        Assert::same(\count($cache), 2);
    }

    public function behavesAsModelOfLeastRecentlyUsed(): void
    {
        $random = new Randomizer(new Mt19937(seed: 42));
        $capacity = 4;
        $cache = new LocalCache($capacity);
        /** @var array<string, int> $model in the order of use, the least recently used first */
        $model = [];

        for ($i = 0; $i < 10_000; ++$i) {
            $key = 'key ' . $random->getInt(0, 7);

            switch ($random->getInt(0, 2)) {
                case 0:
                    $expected = $model[$key] ?? null;

                    if ($expected !== null) {
                        unset($model[$key]);
                        $model[$key] = $expected;
                    }

                    Assert::same($cache->get($key), $expected);
                    break;
                case 1:
                    unset($model[$key]);
                    $model[$key] = $i;

                    if (\count($model) > $capacity) {
                        unset($model[array_key_first($model)]);
                    }

                    $cache->set($key, $i);
                    break;

                default:
                    unset($model[$key]);
                    $cache->delete($key);
            }
        }

        foreach ($model as $key => $value) {
            Assert::same($cache->get($key), $value);
        }
    }

    public function expiryOfReplacedEntryDoesNotDropItsReplacement(): void
    {
        $cache = new LocalCache();
        $cache->set('with ttl', 1, sec(1));
        $cache->set('without ttl', 1, sec(1));

        $cache->set('with ttl', 2, hours(1));
        $cache->set('without ttl', 2);
        delay(sec(2));
        $cache->set('another key', 0);

        Assert::same($cache->get('with ttl'), 2);
        Assert::same($cache->get('without ttl'), 2);
    }

    public function entriesExpireAfterHeapIsRebuilt(): void
    {
        $cache = new LocalCache();

        for ($i = 0; $i < 10; ++$i) {
            $cache->set('rewritten', $i, sec(1));
        }

        $cache->set('expiring', 1, sec(1));
        $cache->set('lasting', 1, hours(1));
        delay(sec(2));

        Assert::same(\count($cache), 1);
        Assert::null($cache->get('rewritten'));
        Assert::null($cache->get('expiring'));
        Assert::same($cache->get('lasting'), 1);
    }

    public function entryKeepsItsExpiryWhenHeapIsRebuiltByOthers(): void
    {
        $cache = new LocalCache();
        $cache->set('kept', 1, sec(1));

        for ($i = 0; $i < 10; ++$i) {
            $cache->set('rewritten', $i, sec(1));
        }

        $cache->delete('rewritten');
        delay(sec(2));

        Assert::same(\count($cache), 0);
    }

    public function ttlBeyondClockIsNoExpiry(): void
    {
        $cache = new LocalCache();

        $cache->set('a', 1, sec(9_223_372_035));
        delay(hours(1000));

        Assert::same($cache->get('a'), 1);
    }

    public function valueOfTtlThatIsNotPositiveIsNotKept(): void
    {
        $cache = new LocalCache();
        $reference = self::storeObject($cache, 'a', ttl: ZERO);

        Assert::null($reference->get());
    }

    public function deletedValueIsFreed(): void
    {
        $cache = new LocalCache();
        $reference = self::storeObject($cache, 'a', ttl: hours(1));

        $cache->delete('a');

        Assert::null($reference->get());
    }

    public function replacedValueIsFreed(): void
    {
        $cache = new LocalCache();
        $withTtl = self::storeObject($cache, 'with ttl', ttl: hours(1));
        $withoutTtl = self::storeObject($cache, 'without ttl', ttl: null);

        $cache->set('with ttl', 2, hours(1));
        $cache->set('without ttl', 2);

        Assert::null($withTtl->get());
        Assert::null($withoutTtl->get());
    }

    public function evictedValueIsFreed(): void
    {
        $cache = new LocalCache(capacity: 1);
        $reference = self::storeObject($cache, 'a', ttl: hours(1));

        $cache->set('b', 2);

        Assert::null($reference->get());
    }

    public function expiredValueIsFreedWhenRead(): void
    {
        $cache = new LocalCache();
        $reference = self::storeObject($cache, 'a', ttl: sec(1));
        delay(sec(2));

        $cache->get('a');

        Assert::null($reference->get());
    }

    public function expiredValueThatIsNotReadIsFreedBySet(): void
    {
        $cache = new LocalCache();
        $reference = self::storeObject($cache, 'a', ttl: sec(1));
        delay(sec(2));

        $cache->set('another key', 0);

        Assert::null($reference->get());
    }

    public function overCapacityExpiredEntriesAreDroppedBeforeLiveOnes(): void
    {
        $cache = new LocalCache(capacity: 3);
        $cache->set('live', 1);
        $cache->set('expiring a', 2, sec(1));
        $cache->set('expiring b', 3, sec(1));

        delay(sec(2));
        $cache->set('new a', 4);
        $cache->set('new b', 5);

        Assert::same($cache->get('live'), 1);
        Assert::same($cache->get('new a'), 4);
        Assert::same($cache->get('new b'), 5);
    }

    public function setOfSameKeyWhileDestructorOfReplacedValueWaitsKeepsTheLaterValue(): void
    {
        $cache = new LocalCache();
        $cache->set('a', new class {
            public function __destruct()
            {
                delay(sec(1));
            }
        });

        group(static function (Group $group) use ($cache) {
            $group->spawn(static fn() => $cache->set('a', 2));
            $group->spawn(static function () use ($cache) {
                delay(ms(1));
                $cache->set('a', 3);
            });
        });

        Assert::same($cache->get('a'), 3);
        Assert::count($cache, 1);
    }

    /**
     * @param LocalCache<mixed> $cache
     * @return \WeakReference<\stdClass>
     */
    private static function storeObject(LocalCache $cache, string $key, ?Duration $ttl): \WeakReference
    {
        $value = new \stdClass();
        $cache->set($key, $value, $ttl);

        return \WeakReference::create($value);
    }
}
