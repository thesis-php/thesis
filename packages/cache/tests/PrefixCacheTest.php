<?php

declare(strict_types=1);

namespace Thesis\Cache;

use Testo\Assert;
use Testo\Test;
use function Thesis\Time\hours;

#[Test]
final class PrefixCacheTest extends CacheContract
{
    protected function createCache(): Cache
    {
        /** @var PrefixCache<string> */
        return new PrefixCache(new LocalCache(), prefix: 'prefix:');
    }

    public function writesToInnerCacheUnderPrefixedKey(): void
    {
        $inner = new LocalCache();
        $cache = new PrefixCache($inner, prefix: 'users:');

        $cache->set('1', 'value', hours(1));

        Assert::same($inner->get('users:1'), 'value');
        Assert::null($inner->get('1'));
    }

    public function readsAndDeletesUnderPrefixedKey(): void
    {
        $inner = new LocalCache();
        $cache = new PrefixCache($inner, prefix: 'users:');
        $inner->set('users:1', 'value');
        $inner->set('1', 'not prefixed');

        $read = $cache->get('1');
        $cache->delete('1');

        Assert::same($read, 'value');
        Assert::null($inner->get('users:1'));
        Assert::same($inner->get('1'), 'not prefixed');
    }

    public function prefixesOverOneCacheDoNotMix(): void
    {
        $inner = new LocalCache();
        $users = new PrefixCache($inner, prefix: 'users:');
        $orders = new PrefixCache($inner, prefix: 'orders:');

        $users->set('1', 'user');
        $orders->set('1', 'order');

        Assert::same($users->get('1'), 'user');
        Assert::same($orders->get('1'), 'order');
    }

    public function exposesPrefix(): void
    {
        $cache = new PrefixCache(new LocalCache(), prefix: 'users:');

        Assert::same($cache->prefix, 'users:');
    }
}
