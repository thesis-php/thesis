<?php

declare(strict_types=1);

namespace Thesis\Cache;

use Testo\Assert;
use Testo\Test;
use function Thesis\Time\hours;

#[Test]
final class NullCacheTest
{
    public function getReturnsDefaultAfterSet(): void
    {
        $cache = new NullCache();

        $cache->set('a', 'value');
        $cache->set('b', 'value', hours(1));

        Assert::null($cache->get('a'));
        Assert::same($cache->get('b', 'default'), 'default');
    }

    public function deleteIsNotError(): void
    {
        $cache = new NullCache();

        $cache->delete('a');

        Assert::null($cache->get('a'));
    }

    public function atomicCacheOverItComputesEveryTime(): void
    {
        $cache = new AtomicCache(new NullCache());
        $computations = 0;
        $compute = static function () use (&$computations) {
            return ++$computations;
        };

        $first = $cache->getOrCompute('a', $compute);
        $second = $cache->getOrCompute('a', $compute);

        Assert::same([$first, $second], [1, 2]);
    }
}
