# thesis/cache

A cache interface, an in-process cache and stampede protection. The API is described in `README.md`.

This file is for agents changing this package. It is not loaded while an agent works in another package,
so how to *use* this package belongs in `README.md` or in a skill under `skills/<skill-name>/SKILL.md`.

## Invariants that must not be broken

- A TTL is `?Time\Duration`: null keeps the entry, zero or negative deletes it (as in PSR-16).
- A miss is told from a stored null only by the default of `get()`: no method may treat null as a miss.
- Nothing in the package runs in the background: no timers, no spawned tasks, so a cache needs no `Group`
  and no `run()` (`clients.md`).
- `LocalCache` keeps the order of use in a list through the entries (`Entry::$newer`, `Entry::$olderKey`),
  the array is only an index: `array_key_first()` of an array in LRU order scans the holes that unset() leaves
  at its start, O(n) per eviction until PHP compacts the table. The link back is a key, not the entry,
  so the list has no cycles. Every operation is O(1), O(log n) for an entry with a TTL.
- An entry that leaves `LocalCache` (deleted, replaced, evicted, expired and read) is freed at once, its value too:
  the expiry heap holds entries weakly (`Internal\Expiry`). An expired entry that is not read is dropped by the next
  `set()`, which first drops all entries that have expired, before it evicts over the capacity. Dead nodes are
  skipped at the top, and the heap is rebuilt from its live nodes once it holds more than twice as many as there are
  entries with a TTL. A test checks a `\WeakReference` for each way out.
  `count()` drops the expired entries the same way, so it never counts an entry that `get()` would miss.
- A TTL that `Instant::add()` cannot represent is no expiry, and nothing changes before it is computed:
  `set()` never throws for a TTL and never loses the old value on a failure.
- A numeric string key becomes an int as an array key: `LocalCache` takes keys from `Entry::$key`, never from
  the array, and the contract tests `'1'`, `'01'` and `'-1'`.
- Time is taken from `Thesis\Async\Instant`, never from `hrtime()` or a wall clock: virtual time must move it.
- `AtomicCache` computes in the fiber of the caller that holds the lock of the key (`synchronizedKey()`,
  reentrant per fiber), so a cancellation reaches only that caller and the computation it started.
  A child task of the computation does not hold the lock: it deadlocks on the key, documented and tested.
- `AtomicCache::set()` and `delete()` take the lock of the key: otherwise a delete during a computation is
  overwritten by a value computed from the data before the change, and the stale value lives for its whole TTL.
  `get()` and a hit of `getOrCompute()` take no lock (a hit is the common case, a distributed lock is a round trip);
  a miss reads again under the lock before computing. A `set()` or `delete()` cancelled while waiting is not applied:
  rejected, shielding them inside, because the wait for a computation would then be unbounded and uncancellable.
  The caller decides with `shield()`.

## Decisions

- Our own interface, not PSR-6/PSR-16: their TTL is `null|int|DateInterval`, values are `mixed` without generics,
  keys are restricted to the characters of other backends, and PSR-6 adds items and deferred saves for nothing.
  PSR-16 may come as a bridge (`cache-psr16`) for libraries that take it; the other direction is useless,
  a synchronous PSR cache blocks the event loop.
- `get($key, $default)` with a template type of the default, not `?Entry` or an exception on a miss:
  one call, exact types, and a stored null is told from a miss by a sentinel default.
  Rejected: a default in the constructor of the cache. The default belongs to the call site, and one cache
  is shared by callers that need different ones.
- No `has()`: it races with other fibers, `get()` with a default answers the same question atomically.
- Stampede protection is a decorator over any `Cache` and any `KeyedMutex`, not a method every implementation
  writes: the same code protects a local cache with `LocalKeyedMutex` and a Redis one with a distributed mutex.
  It implements `Cache`, so it replaces the inner cache for every caller and every write goes through the lock.
  Rejected: a function `getOrCompute($cache, ...)` with a mutex per cache in a `WeakMap`. An invalidation by
  a plain `$cache->delete()` would bypass the lock, and a second function for it would be forgotten.
- From amphp's `AtomicCache` we take the locked writes and the read again under the lock. Rejected:
  a required mutex (the default is one of the object, a pair with it), `null` as a miss, wrapping every failure
  in `CacheException` (the caller loses the type of the failure of its own `$compute`), a non-reentrant lock
  (a nested call of the same key deadlocks), `compute()` and `computeIfPresent()` until someone needs them.
- Rejected: one shared computation that every waiter awaits (single flight). It would have to run in a task
  of its own, not of the first caller (cancelling that caller would fail the rest), so the cache would need
  an owner of background work. Under a lock, a cancellation only costs the next waiter
  a computation of its own.
- `tests/CacheContract.php` is the test every implementation extends: `createCache()`, `ttl()` for a store
  that expires on ticks (at least `sec(2)` then), `wait()` for a store on another clock under virtual time.
  Only what every cache must do, with string values, so a `Cache<string>` of a remote store passes as is.
  What one implementation adds (null, objects as they are, an exact TTL, eviction) stays in its test.
  It is in `tests/`, so only packages of the monorepo reach it (by `requireDev`): the archive has no `tests/`.
  Shipping it to third parties (`dist` or a package of its own) waits for one who needs it.
- A miss inside the package is `Internal\Missing::Value`, an enum case: no user value is identical to it,
  and PHPStan narrows `V|Missing` by `!==`.
- Rejected: a sweep of all entries for expiry (on a timer, or once per as many writes as there are entries):
  a full cache of live entries would pay O(n) on a `set()` that has to evict. The heap costs O(log n) per entry
  with a TTL. Rejected: heap nodes that hold the entry strongly, they kept every replaced value until its TTL.
- `PrefixCache` prepends the prefix as is: no separator of its own (the store may have a convention) and no check
  that prefixes of one store do not collide. The prefix is a `public readonly` property, not a getter
  (`getKeyPrefix()` of amphp).
- `NullCache` does not extend `CacheContract`: it breaks every rule there that a stored value is read back.
- Taken from amphp later, when a store needs it: a decorator with a codec over `Cache<string>`
  (`SerializedCache`), `compute()` (an atomic read-modify-write), a marker interface for failures of a store.
  Rejected: `StringCache` and its adapter (it is `Cache<string>`), `delete(): ?bool`, iteration of `LocalCache`
  (it exposes the internals; `Countable` is taken, for tests and metrics), `ForbidCloning`/`ForbidSerialization`.
