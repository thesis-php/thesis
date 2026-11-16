<?php

declare(strict_types=1);

namespace Thesis\Cache;

use Testo\Assert;
use Testo\Data\DataSet;
use Time\Duration;
use function Thesis\Async\delay;
use function Thesis\Time\multiply;
use function Thesis\Time\ns;
use function Thesis\Time\sec;

/**
 * The tests every {@see Cache} must pass. A test of an implementation extends it and creates the cache:
 *
 *     #[Test]
 *     final class RedisCacheTest extends CacheContract
 *     {
 *         protected function createCache(): Cache { return new RedisCache($this->client, prefix: uniqid()); }
 *     }
 *
 * Values are strings, so a cache that serializes (`Cache<string>` of a remote store) passes as is.
 * What only some implementations do (null, objects kept as they are, an exact TTL, eviction) is tested
 * by the test of the implementation.
 */
abstract class CacheContract
{
    /**
     * A new, empty cache for every test: no key of another test may be visible.
     *
     * @return Cache<string>
     */
    abstract protected function createCache(): Cache;

    /**
     * The TTL of the tests that expire an entry. They wait twice as long, so an entry must outlive a read
     * right after set() and be gone after two TTLs: a store that expires on ticks of a second needs at least sec(2).
     */
    protected function ttl(): Duration
    {
        return sec(1);
    }

    /**
     * Lets $duration pass for the cache. delay() fits both the virtual time of `VirtualTimePlugin` and a store
     * on the real clock without it. Under the plugin with a store on another clock (a server), the wait has
     * to block the process instead (`usleep()`): virtual time would end delay() at once.
     */
    protected function wait(Duration $duration): void
    {
        delay($duration);
    }

    final public function getReturnsDefaultOnMiss(): void
    {
        $cache = $this->createCache();

        Assert::null($cache->get('a'));
        Assert::same($cache->get('a', 'default'), 'default');
    }

    final public function getReturnsStoredValue(): void
    {
        $cache = $this->createCache();

        $cache->set('a', 'value');

        Assert::same($cache->get('a'), 'value');
    }

    #[DataSet([''])]
    #[DataSet(["\x00\xFF\xFE binary \r\n"])]
    #[DataSet(['значение'])]
    final public function valueIsAnyString(string $value): void
    {
        $cache = $this->createCache();

        $cache->set('a', $value);

        Assert::same($cache->get('a', 'missing'), $value);
    }

    final public function setReplacesValue(): void
    {
        $cache = $this->createCache();

        $cache->set('a', 'first');
        $cache->set('a', 'second');

        Assert::same($cache->get('a'), 'second');
    }

    final public function keysDoNotMix(): void
    {
        $cache = $this->createCache();

        $cache->set('a', 'value of a');
        $cache->set('b', 'value of b');

        Assert::same($cache->get('a'), 'value of a');
        Assert::same($cache->get('b'), 'value of b');
    }

    final public function keyIsAnyString(): void
    {
        $keys = ['1', '01', '-1', '', '{}()/\@: ', "\x00\n\r\t\xFF", 'ключ', str_repeat('long', 1000)];
        $cache = $this->createCache();

        foreach ($keys as $key) {
            $cache->set($key, "value of {$key}");
        }

        foreach ($keys as $key) {
            Assert::same($cache->get($key), "value of {$key}");
        }

        foreach ($keys as $key) {
            $cache->delete($key);
            Assert::null($cache->get($key));
        }
    }

    final public function numericStringKeyIsNotItsInteger(): void
    {
        $cache = $this->createCache();

        $cache->set('1', 'one');

        Assert::null($cache->get('01'));
        Assert::null($cache->get('1.0'));
    }

    final public function entryExpiresAfterTtl(): void
    {
        $cache = $this->createCache();

        $cache->set('a', 'value', $this->ttl());
        $beforeExpiry = $cache->get('a');
        $this->wait(multiply($this->ttl(), 2));

        Assert::same($beforeExpiry, 'value');
        Assert::same($cache->get('a', 'missing'), 'missing');
    }

    final public function expiredKeyCanBeSetAgain(): void
    {
        $cache = $this->createCache();
        $cache->set('a', 'first', $this->ttl());
        $this->wait(multiply($this->ttl(), 2));

        $cache->set('a', 'second');

        Assert::same($cache->get('a'), 'second');
    }

    final public function readDoesNotProlongTtl(): void
    {
        $cache = $this->createCache();
        $cache->set('a', 'value', multiply($this->ttl(), 2));

        $this->wait($this->ttl());
        $cache->get('a');
        $this->wait(multiply($this->ttl(), 1.5));

        Assert::null($cache->get('a'));
    }

    final public function entryWithoutTtlDoesNotExpire(): void
    {
        $cache = $this->createCache();

        $cache->set('a', 'value');
        $this->wait(multiply($this->ttl(), 2));

        Assert::same($cache->get('a'), 'value');
    }

    final public function setReplacesTtl(): void
    {
        $cache = $this->createCache();
        $cache->set('loses ttl', 'first', $this->ttl());
        $cache->set('gets ttl', 'first');
        $cache->set('longer ttl', 'first', $this->ttl());
        $cache->set('shorter ttl', 'first', multiply($this->ttl(), 10));

        $cache->set('loses ttl', 'second');
        $cache->set('gets ttl', 'second', $this->ttl());
        $cache->set('longer ttl', 'second', multiply($this->ttl(), 10));
        $cache->set('shorter ttl', 'second', $this->ttl());
        $this->wait(multiply($this->ttl(), 2));
        $cache->set('another key', 'value');

        Assert::same($cache->get('loses ttl'), 'second');
        Assert::null($cache->get('gets ttl'));
        Assert::same($cache->get('longer ttl'), 'second');
        Assert::null($cache->get('shorter ttl'));
    }

    #[DataSet([0])]
    #[DataSet([-1])]
    final public function nonPositiveTtlDeletes(int $seconds): void
    {
        $cache = $this->createCache();
        $cache->set('a', 'value');

        $cache->set('a', 'other', sec($seconds));

        Assert::null($cache->get('a'));
    }

    final public function tinyTtlIsAccepted(): void
    {
        $cache = $this->createCache();

        $cache->set('a', 'value', ns(1));
        $this->wait(multiply($this->ttl(), 2));

        Assert::null($cache->get('a'));
    }

    final public function hugeTtlIsAccepted(): void
    {
        $cache = $this->createCache();
        $cache->set('a', 'old');

        $cache->set('a', 'value', sec(9_223_372_035));

        Assert::same($cache->get('a'), 'value');
    }

    final public function deleteRemovesEntry(): void
    {
        $cache = $this->createCache();
        $cache->set('a', 'value');
        $cache->set('b', 'value');

        $cache->delete('a');

        Assert::null($cache->get('a'));
        Assert::same($cache->get('b'), 'value');
    }

    final public function deletedKeyCanBeSetAgain(): void
    {
        $cache = $this->createCache();
        $cache->set('a', 'first');
        $cache->delete('a');

        $cache->set('a', 'second');

        Assert::same($cache->get('a'), 'second');
    }

    final public function deleteOfMissingKeyIsNotError(): void
    {
        $cache = $this->createCache();

        $cache->delete('missing');

        Assert::null($cache->get('missing'));
    }
}
