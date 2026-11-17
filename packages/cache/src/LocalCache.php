<?php

declare(strict_types=1);

namespace Thesis\Cache;

use Thesis\Async\Instant;
use Thesis\Cache\Internal\Entry;
use Thesis\Cache\Internal\Expiries;
use Thesis\Cache\Internal\Expiry;
use Time\Duration;
use Time\TimeException;
use function Thesis\Time\toNs;

/**
 * In-process {@see Cache}: values are kept as they are, not serialized, so an object is shared with whoever
 * gets it and a change to it is seen by every reader.
 *
 * Expiry is measured on {@see Instant}, so `VirtualTime\run()` moves it. An expired entry is dropped when it is read
 * or by the next set(), which first drops every entry that has expired by then. There is no timer: the cache needs
 * no owner of background work, and an expired entry that is never read waits for a set() to be freed.
 * An entry that leaves the cache otherwise (deleted, replaced, evicted) is freed at once.
 * With a capacity, a set() of a new key over it evicts the least recently used entry.
 * A TTL beyond the range of the clock (about 292 years) is no expiry.
 *
 * Never suspends: every method completes within the call, in O(1), or O(log n) for an entry with a TTL.
 *
 * @api
 * @template V
 * @implements Cache<V>
 */
final class LocalCache implements Cache, \Countable
{
    /**
     * A numeric string key becomes an int here: a key is taken from an entry, never from this array.
     *
     * @var array<array-key, Entry<V>>
     */
    private array $entries = [];

    /**
     * @var ?Entry<V>
     */
    private ?Entry $leastRecentlyUsed = null;

    /**
     * @var ?Entry<V>
     */
    private ?Entry $mostRecentlyUsed = null;

    /**
     * Also holds the nodes of entries that have left the cache, until they come to the top or the heap is rebuilt
     * from its live nodes once it holds more than twice as many as there are entries with a TTL.
     *
     * @var Expiries<V>
     */
    private Expiries $expiries;

    private int $entriesWithTtl = 0;

    /**
     * @param ?positive-int $capacity the maximum number of entries, null for no limit
     */
    public function __construct(
        private readonly ?int $capacity = null,
    ) {
        $this->expiries = new Expiries();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $entry = $this->entries[$key] ?? null;

        if ($entry === null) {
            return $default;
        }

        if ($entry->hasExpired(Instant::now())) {
            $this->remove($entry);

            return $default;
        }

        if ($entry !== $this->mostRecentlyUsed) {
            $this->unlink($entry);
            $this->link($entry);
        }

        return $entry->value;
    }

    public function set(string $key, mixed $value, ?Duration $ttl = null): void
    {
        $now = Instant::now();
        $expiresAt = $ttl === null ? null : self::expiresAt($now, $ttl);

        if (isset($this->entries[$key])) {
            $this->remove($this->entries[$key]);
        }

        $this->dropExpired($now);

        if ($expiresAt === false) {
            return;
        }

        $entry = new Entry($key, $value, $expiresAt);
        $this->entries[$key] = $entry;
        $this->link($entry);

        if ($expiresAt !== null) {
            $this->expiries->insert(new Expiry($expiresAt, \WeakReference::create($entry)));
        }

        if ($this->capacity !== null && \count($this->entries) > $this->capacity) {
            \assert($this->leastRecentlyUsed !== null);
            $this->remove($this->leastRecentlyUsed);
        }

        // Frees the memory of the dead nodes of the heap: when and whether it is rebuilt changes no entry.
        // @infection-ignore DecrementInteger, IncrementInteger, GreaterThan, GreaterThanNegotiation, MethodCallRemoval
        if ($this->expiries->count() > 2 * $this->entriesWithTtl) {
            $this->rebuildExpiries();
        }
    }

    public function delete(string $key): void
    {
        if (isset($this->entries[$key])) {
            $this->remove($this->entries[$key]);
        }
    }

    /**
     * The number of entries that have not expired. Drops the expired ones first, like set().
     */
    public function count(): int
    {
        $this->dropExpired(Instant::now());

        return \count($this->entries);
    }

    /**
     * @return Instant|false|null false if the TTL is not positive (the entry is deleted), null for no expiry
     */
    private static function expiresAt(Instant $now, Duration $ttl): null|Instant|false
    {
        if (toNs($ttl) <= 0) {
            return false;
        }

        try {
            return $now->add($ttl);
        } catch (TimeException) {
            return null;
        }
    }

    /**
     * @param Entry<V> $entry
     */
    private function remove(Entry $entry): void
    {
        $this->unlink($entry);
        unset($this->entries[$entry->key]);
    }

    /**
     * Makes $entry the most recently used. It is new or unlinked, so it has no neighbours yet.
     *
     * @param Entry<V> $entry
     */
    private function link(Entry $entry): void
    {
        if ($this->mostRecentlyUsed === null) {
            $this->leastRecentlyUsed = $entry;
        } else {
            $entry->olderKey = $this->mostRecentlyUsed->key;
            $this->mostRecentlyUsed->newer = $entry;
        }

        $this->mostRecentlyUsed = $entry;

        // only decides when the heap of expiries is rebuilt, see set()
        // @infection-ignore NotIdentical, Increment
        if ($entry->expiresAt !== null) {
            ++$this->entriesWithTtl;
        }
    }

    /**
     * @param Entry<V> $entry
     */
    private function unlink(Entry $entry): void
    {
        // @phpstan-ignore offsetAccess.notFound (the older entry is linked, so it is in the index)
        $older = $entry->olderKey === null ? null : $this->entries[$entry->olderKey];
        $newer = $entry->newer;

        if ($older === null) {
            $this->leastRecentlyUsed = $newer;
        } else {
            $older->newer = $newer;
        }

        if ($newer === null) {
            $this->mostRecentlyUsed = $older;
        } else {
            $newer->olderKey = $entry->olderKey;
        }

        $entry->newer = null;
        $entry->olderKey = null;

        // only decides when the heap of expiries is rebuilt, see set()
        // @infection-ignore NotIdentical, Decrement
        if ($entry->expiresAt !== null) {
            --$this->entriesWithTtl;
        }
    }

    /**
     * @param Entry<V> $entry
     */
    private function isCached(Entry $entry): bool
    {
        return ($this->entries[$entry->key] ?? null) === $entry;
    }

    private function dropExpired(Instant $now): void
    {
        while (!$this->expiries->isEmpty() && $this->expiries->top()->at <= $now) {
            $entry = $this->expiries->extract()->entry->get();

            if ($entry !== null && $this->isCached($entry)) {
                $this->remove($entry);
            }
        }
    }

    private function rebuildExpiries(): void
    {
        /** @var Expiries<V> */
        $expiries = new Expiries();

        foreach ($this->expiries as $expiry) {
            $entry = $expiry->entry->get();

            if ($entry !== null && $this->isCached($entry)) {
                $expiries->insert($expiry);
            }
        }

        $this->expiries = $expiries;
    }
}
