# Thesis Async

Structured concurrency for PHP fibers on top of [Revolt](https://revolt.run): cancellation is a property of the task tree, not a function parameter.

> **Experimental.** The API is unstable.

## Installation

```shell
composer require thesis/async
```

The amphp bridge, [`ampCancellable()`](#amphp-ampcancellable), needs `amphp/amp` v3, which is not installed with the package.

## The problem

With fibers, asynchronous PHP code finally looks synchronous — except for cancellation.
In the amphp v3 model a `Cancellation` token is passed by hand through every signature between the controller and the socket:

```php
function dashboard(int $userId, ?Cancellation $cancellation = null): array
{
    $deferred = new DeferredCancellation();
    $cancellation = new CompositeCancellation($cancellation ?? new NullCancellation(), $deferred->getCancellation());

    $futures = [
        'user' => async(fetchUser(...), $userId, $cancellation),
        'orders' => async(fetchOrders(...), $userId, $cancellation),
    ];

    try {
        return Future\await($futures, $cancellation);
    } catch (\Throwable $exception) {
        $deferred->cancel($exception); // siblings are asked to stop, but nobody waits for them

        throw $exception;
    }
}
```

- Forget the token in one place and the whole subtree becomes uncancellable.
- A timeout is one more token to compose at every level.
- `async()` is unstructured: a future outlives the function that started it.
- `CancelledException` is a regular exception: one `catch (\Exception)` in a retry loop and the cancellation is gone.

## The same code with scopes

```php
use function Thesis\Async\all;

function dashboard(int $userId): array
{
    return all([
        'user' => static fn() => fetchUser($userId),
        'orders' => static fn() => fetchOrders($userId),
    ]);
}
```

`fetchUser()` and `fetchOrders()` take no cancellation parameter.
If one of them fails, the other is cancelled **and joined**, and only then the failure is rethrown.

## Usage

### Concurrent calls: `all()`

```php
use function Thesis\Async\all;

['sales' => $sales, 'costs' => $costs] = all([
    'sales' => loadSales(...),
    'costs' => loadCosts(...),
]);

$report = new Report($sales, $costs);
```

`all()`, `allSettled()`, `race()`, `some()`, `map()` and `each()` cover most cases without a task handle. All of them are built on `group()`.

`all()` keeps the keys but not a type per key: its signature is `array<K, callable(): T>` → `array<K, T>`,
so the result above is typed as `array<'sales'|'costs', Sales|Costs>`. When the results have different types,
spawn the tasks in a `group()` and await their handles: a `Task<T>` is typed precisely.

```php
use Thesis\Async\Group;
use function Thesis\Async\group;

$report = group(static function (Group $group) {
    $sales = $group->spawn(loadSales(...)); // Task<Sales>
    $costs = $group->spawn(loadCosts(...)); // Task<Costs>

    return new Report($sales->await(), $costs->await());
});
```

### Tasks: `group()`

When tasks are started dynamically or a background task is needed, use a group directly.
A concurrent task can only be started inside a group, and `group()` does not return until every task has finished.
There is no way to leak a running fiber.

The group is passed explicitly on purpose. Only a function that takes a `Group` can leave work running after it returns,
and its signature says so.

`spawn()` does not run the task inside the call: tasks are started through the event loop's microtask queue,
so they begin at the body's next wait (or when the body returns and the group joins).

Start early, decide later. The body works concurrently with its tasks and may cancel the ones it no longer needs:

```php
use Thesis\Async\Group;
use function Thesis\Async\group;

$page = group(static function (Group $group) use ($userId) {
    $avatar = $group->spawn(static fn() => fetchAvatar($userId)); // starts at the body's next wait
    $profile = loadProfile($userId);                            // meanwhile, in the body

    if (!$profile->showsAvatar) {
        $avatar->cancel(); // not a failure: the group goes on

        return new Page($profile);
    }

    return new Page($profile, $avatar->await());
});
```

The number of tasks is not known in advance:

```php
group(static function (Group $group) use ($server) {
    while (null !== $connection = $server->accept()) {
        $group->spawn(static fn() => handle($connection)); // a failure here fails the whole group: handle() catches its own errors
    }
}); // on cancellation, stops accepting and waits for the connections in progress
```

A background task that lives exactly as long as the work it accompanies:

```php
use function Thesis\Async\delay;

group(static function (Group $group) use ($message) {
    $group->daemon(static function () use ($message) {
        while (true) {
            delay(10);
            $message->touch(); // heartbeat
        }
    });

    process($message);
}); // the daemon is cancelled and joined here
```

`Group::cancelTasks()` cancels every task and daemon, but not the body: the body owns the group,
and `group()` returns its result. Work that has to be stoppable this way belongs in a task.
It is sticky: a task spawned afterwards never runs, its handle is done as soon as it would have started,
and awaiting it throws `TaskCancelled`.

Awaiting a cancelled task throws `TaskCancelled`, a regular exception: the awaiting code is not cancelled,
it has just lost a dependency. A task that let through the `TaskCancelled` of another task it awaited
throws it too: as in Kotlin and Java, the two cannot be told apart. Awaiting a task that is already done returns
its result or throws its failure without suspending: it is not a cancellation point then, even in a cancelled scope,
and does not yield to the event loop. Only a cancelled task awaited from a cancelled scope throws `Cancelled`
instead of `TaskCancelled`: the awaiter ends as cancelled, so a `timeout()` around it still throws `TimedOut`.

If an outer cancellation cuts a task short, `group()` throws `Cancelled` even if the body never awaited that task:
a group never pretends that its work is done.

Called from `{main}`, `group()` runs the event loop itself while it joins, and Revolt may throw out of it.
If a foreign event loop callback throws, that is a failure of the group: the tasks are cancelled and joined,
and the `UncaughtThrowable` is rethrown (unless an earlier failure takes precedence). If the loop runs dry
while tasks are waiting, it is a deadlock: the tasks are cancelled, and once they have unwound, Revolt's error is rethrown.

### Failures that were not rethrown: `suppressed()`

A group rethrows only its first failure. Others may follow while the group is cancelled: a sibling that failed
in the same tick, a rollback that failed during the cancellation. They are not lost: `suppressed()` returns them.
The rethrown error itself is left as it was, so `catch` by type works as before.

```php
use function Thesis\Async\suppressed;

try {
    handle($request);
} catch (\Throwable $exception) {
    $logger->error('Request failed', ['exception' => $exception]);

    foreach (suppressed($exception) as $other) {
        $logger->error('Also failed', ['exception' => $other]);
    }
}
```

Nobody sees them unless asked, so ask where errors are logged. Nested groups give a tree: an error that left an inner group
has suppressed errors of its own. It stays a tree even if one error reaches both groups (a failed `Deferred` awaited
in each): an error is never suppressed under one from its own list. The list goes away with the error it belongs to.

### Deadlines: `timeout()`

A deadline covers everything inside the closure, including nested groups.

```php
use Thesis\Async\TimedOut;
use function Thesis\Async\timeout;

try {
    $recommendations = timeout(0.3, static fn() => fetchRecommendations($userId));
} catch (TimedOut) {
    $recommendations = [];
}
```

Nested timeouts do not confuse each other: an inner `timeout()` never reports an outer deadline as its own,
and an outer cancellation always outranks a deadline. A deadline that is zero or negative has already passed:
the closure runs, but its first wait throws. A `NAN` deadline is a bug and throws `\ValueError` before the closure runs.
`delay()` of zero or less waits as `delay(0)`, as in Go and Python: until the next tick, a cancellation point all the same.

When running out of time is an expected outcome, `timeoutOr()` returns a default instead of throwing:

```php
use function Thesis\Async\timeoutOr;

$recommendations = timeoutOr(0.3, [], static fn() => fetchRecommendations($userId));
```

It is not `try { timeout() } catch (TimedOut)`: that would also swallow a `TimedOut` of a nested `timeout()`
that the closure lets out. `timeoutOr()` gives the default only when its own deadline cut the closure short.

If the closure may return the default itself (`null`, for example), pass a default it never returns,
as with [`Channel::tryReceive()`](#producers-and-consumers-channel):

```php
$user = timeoutOr(0.3, $expired = new \stdClass(), static fn() => findUser($userId)); // ?User

if ($user === $expired) {
    // the deadline, not a missing user
}
```

`now()` is the monotonic clock of deadlines, for one computed ahead: `$at = now() + 5`, later `timeout($at - now(), ...)`.

`remaining()` tells how many seconds are left until the nearest deadline, or `null` if there is none,
so the deadline can be passed on to where a scope cannot reach: a database, a remote call.
A task sees the deadline under which its group runs; inside `shield()` outer deadlines are not visible,
since nothing would enforce them there.

```php
use function Thesis\Async\remaining;

$seconds = remaining();

if ($seconds !== null) {
    $connection->execute(sprintf('SET statement_timeout = %d', (int) ceil($seconds * 1000)));
}
```

It only reads the clock, and it is zero once the deadline has passed, even if the timer has not fired yet.

### First success wins: `race()`

The losers are cancelled and joined. A failure does not end the race while another contender can still win;
if every contender fails, the first failure is rethrown.

```php
use function Thesis\Async\delay;
use function Thesis\Async\race;

// Hedged request: ask the replica only if the primary is slow.
$value = race([
    static fn() => read('primary', $key),
    static function () use ($key) {
        delay(0.05);

        return read('replica', $key);
    },
]);
```

If every contender fails, the other failures are among the `suppressed()` ones of the rethrown one.

### Quorum: `some()`

`race()` is `some(1, ...)`. `some()` waits for the first `$count` successes, cancels and joins the rest,
and returns the results with their keys in the order they succeeded:

```php
use function Thesis\Async\some;

// Two replicas out of three agree: the write is durable.
$acks = some(2, [
    'a' => static fn() => write('a', $record),
    'b' => static fn() => write('b', $record),
    'c' => static fn() => write('c', $record),
]);
```

It fails as soon as `$count` successes are no longer possible, not when every task is done: the rest are cancelled,
the first failure is rethrown, and the others (including those of tasks failing while being cancelled) are its `suppressed()` ones.
Fewer tasks than `$count` is a `\ValueError`.

### Every outcome: `allSettled()`

Waits for every task; a failure neither cancels the others nor is rethrown. Results and failures come apart,
each with the keys of the tasks and in their order:

```php
use function Thesis\Async\allSettled;
use function Thesis\Async\timeout;

[$pages, $errors] = allSettled(array_map(
    static fn(string $url) => static fn() => timeout(0.1, static fn() => fetch($url)),
    $urls,
));
```

`Cancelled` is not an outcome: `allSettled()` cancels nothing, so a cancelled task means the caller is cancelled,
and `allSettled()` throws as any wait does. That is why the deadline above is per task: a `timeout()` around `allSettled()`
would throw `TimedOut` and drop the pages that were ready, while one per task makes it a failure of that task.

### Bounded concurrency: `each()` and `map()`

```php
use function Thesis\Async\each;
use function Thesis\Async\map;

each($urls, 20, crawl(...));

$pages = map($urls, 20, static fn(string $url) => fetch($url)); // keys and order of $urls
```

Items are processed by at most `concurrency` tasks, not by a task per item: a task takes the next item once it is done
with the previous one, and a new task is started only when an item finds the running ones busy. So consecutive calls of
the callable may share a fiber: state kept per fiber (by third-party code, for example) is not reset between items.

The tasks iterate the items one at a time, so the iterable may itself wait, for example, for the next page of an API.
The next item is pulled only once a task is free for it, and none is pulled after a failure.
The callable gets the value only, so a function like `strval(...)` fits. `map()` keeps the keys in its result,
so it needs array keys; `each()` ignores them: a generator may yield objects or repeat them.

Since the tasks pull the items, a generator runs in their fibers, not in the caller's. It sees the caller's
[scoped values](#context-of-a-request-scopedvalue), but not the rest of its fiber-local state: a lock the caller holds
through `synchronized()` is not held there, so a generator that takes the same lock waits for the caller,
which waits for `each()` — a deadlock.

### Cleanup: `shield()`

Once a scope is cancelled, every further wait in it fails immediately. Cleanup that has to wait runs under a shield:

```php
use function Thesis\Async\shield;

try {
    $connection->execute('UPDATE accounts ...');
    $connection->execute('COMMIT');
} catch (\Throwable $exception) {
    shield(static fn() => timeout(1, static fn() => $connection->execute('ROLLBACK')));

    throw $exception;
}
```

### Shutdown and reload: `awaitSignal()` and `trapSignals()`

A signal does not cancel anything by itself. `awaitSignal()` waits for one of the signals and returns the one received,
so a graceful shutdown is a group whose body returns once the signal comes: the daemons are cancelled and joined.
`trapSignals()` catches signals for as long as its callable runs, for those that come again and again.

```php
use Thesis\Async\Group;
use Thesis\Async\SignalTrap;
use function Thesis\Async\awaitSignal;
use function Thesis\Async\group;
use function Thesis\Async\trapSignals;

group(static function (Group $group) use ($server, $config) {
    $group->daemon(static fn() => $server->serve()); // stops its own way on cancellation: see shield()

    $group->daemon(static fn() => trapSignals([SIGHUP], static function (SignalTrap $signals) use ($config) {
        foreach ($signals as $_) {
            $config->reload();
        }
    }));

    awaitSignal(SIGINT, SIGTERM);
});
```

The two differ in when a signal gets its default action, which for most signals is to kill the process.
`awaitSignal()` handles them only while it waits, so a second Ctrl+C kills the process if the shutdown hangs.
`trapSignals()` handles them until it returns, so a SIGHUP that comes during `reload()` waits in the trap.
Like the OS, a trap keeps at most one pending signal per number: ten SIGHUPs during a reload ask for one more.

Revolt installs the handlers at the next tick of the event loop. Both are waits like any other: cancellable,
and they keep the event loop running. They need a driver that supports signals: ext-pcntl, ext-ev or ext-uv.

### CPU-bound loops: `checkpoint()`

A loop that never waits starves the event loop: timers, including `timeout()` deadlines, cannot fire,
and other tasks do not run. `checkpoint()` yields to the event loop for one tick and throws `Cancelled`
if the scope is cancelled by then. It costs a full wait, so call it every N iterations:

```php
use function Thesis\Async\checkpoint;

foreach ($rows as $i => $row) {
    if ($i % 1000 === 0) {
        checkpoint();
    }

    $hash = hash('xxh128', $hash . serialize($row));
}
```

### Is it cancelled? `isCancelled()`

`isCancelled()` tells whether the next wait in the current scope would throw `Cancelled`. It only reads a flag:
it neither yields to the event loop nor throws. Inside `shield()` an outer cancellation is not visible.

```php
use function Thesis\Async\isCancelled;

try {
    $connection->execute($query);
} catch (ConnectionClosed $exception) {
    if (!isCancelled()) { // a connection closed because the request was cancelled is not an error
        $logger->error('Query failed', ['exception' => $exception]);
    }

    throw $exception;
}
```

### Code that does not wait through `suspend()`: `onCancel()`

`onCancel()` calls a callback once the current scope is cancelled, by its owner or through an ancestor.
It is for cancellation that has to be delivered elsewhere: an `Amp\Cancellation`, a request to abort a query on the server.

```php
use function Thesis\Async\onCancel;

$unsubscribe = onCancel(static function () use ($connection, $queryId) {
    $connection->cancelQuery($queryId); // a separate request, the query itself fails then
});

try {
    return $connection->query($sql);
} finally {
    $unsubscribe();
}
```

It only observes: a callback cannot be used to cancel a scope it was not given.
The callback runs from the event loop's microtask queue, never inside the code that cancels, and must not throw.
If the scope is cancelled, the callback is called exactly once and always before the scope ends: if the queue has not
reached it by then, the code leaving the scope calls it itself. So a `cancelQuery()` never arrives after the query
has ended and the connection has gone back to a pool to run someone else's query.
It is never called after unsubscribing, even if the cancellation has already happened;
if the scope already is cancelled, it is called soon. Inside `shield()` an outer cancellation does not reach it.
When the scope ends without being cancelled, the callback is dropped, so unsubscribing is optional then.

### A value from elsewhere: `Deferred`

A one-shot value that one side completes and any number of fibers await. `await()` is cancellable;
cancelling one awaiter affects neither the deferred nor the other awaiters.

```php
use Thesis\Async\Deferred;

/** @var Deferred<Response> */
$response = new Deferred();
$pending[$requestId] = $response; // the connection's reader calls $response->complete($frame) or ->fail($error)

$connection->send($request);

return timeout(5, $response->await(...));
```

`complete()` and `fail()` return `false` if the deferred is already done. A deferred cannot fail with `Cancelled`:
its awaiters are not cancelled, so fail it with a regular exception.

Awaiting a deferred that is already done returns at once, without suspending: it is not a cancellation point then
and does not yield to the event loop.

### Exclusive access: `Mutex` and `Semaphore`

Fibers switch only at waits, so a critical section that never waits needs no lock.
One that does — a request and its response on a connection without multiplexing, a message written as several frames — needs a mutex:

```php
use Thesis\Async\LocalMutex;
use function Thesis\Async\synchronized;

final class Connection
{
    private readonly LocalMutex $mutex;

    public function __construct()
    {
        $this->mutex = new LocalMutex();
    }

    public function query(string $sql): Result
    {
        return synchronized($this->mutex, function () use ($sql) {
            $this->send($sql);

            return $this->receive();
        });
    }
}
```

`synchronized()` releases the lock whatever the closure ends with, including cancellation.
It is reentrant within a fiber: if `query()` called another method under the same mutex, that call would run right away.
A task started inside is another fiber and waits for the lock as anyone else.

When the lock has to outlive one call, take it explicitly: a `Lock` belongs to whoever holds the object,
so it may be stored or released by another fiber. It is one-shot: a second `release()` does nothing.
`acquire()` is not reentrant: a holder that acquires again waits forever.

A semaphore allows several holders at a time; `Mutex` is a `Semaphore` with one permit:

```php
use Thesis\Async\LocalSemaphore;

$slots = new LocalSemaphore(10);

group(static function (Group $group) use ($slots, $jobs) {
    foreach ($jobs as $job) {
        $slot = $slots->acquire(); // the body waits for a free slot, the task releases it
        $group->spawn(static function () use ($slot, $job) {
            try {
                handle($job);
            } finally {
                $slot->release();
            }
        });
    }
});
```

Both are fair: a released permit goes to the first waiter, and a newcomer cannot overtake it.
A waiter cancelled after the handover keeps the permit, as with any wait: its next wait throws `Cancelled`.

`Mutex` and `Semaphore` are interfaces, so the permits may live elsewhere: a Redis key, a Postgres advisory lock.
An implementation supplies the callable that returns the permit, and `Lock` calls it under `shield()`:
a release that has to wait still can in `finally` of a cancelled scope. Such a lock is released explicitly:
an unreleased `Lock` releases itself in its destructor, which may run while the engine destroys a fiber,
and a wait there throws `FiberError`.

When the resources are many and not known in advance — a user, an order, a file — take a lock per key.
`KeyedMutex` and `KeyedSemaphore` take the key in `acquire()`, an `int` or a `string` (as in an array, `'1'` is the key `1`),
and `synchronizedKey()` is `synchronized()` for one key:

```php
use Thesis\Async\LocalKeyedMutex;
use function Thesis\Async\synchronizedKey;

$accounts = new LocalKeyedMutex();

synchronizedKey($accounts, $accountId, static function () use ($accountId, $amount) {
    $balance = loadBalance($accountId);     // waits
    storeBalance($accountId, $balance - $amount);
}); // other accounts do not wait for this one
```

Within a key they behave as `Mutex` and `Semaphore`: fair, cancellable, reentrant under `synchronizedKey()` per key.
`LocalKeyedSemaphore` keeps a key only while someone holds or waits for it, so keys that come from outside do not pile up.

### Rate limits: `RateLimiter`

A semaphore bounds how many calls run at once; an API that allows 10 requests a second bounds how often they start.
`LocalTokenBucketRateLimiter` is a token bucket: it holds up to `$burst` tokens, gains `$perSecond` of them a second, and a call takes one:

```php
use Thesis\Async\LocalTokenBucketRateLimiter;

$github = new LocalTokenBucketRateLimiter(perSecond: 10, burst: 10);

each($repositories, 5, static function (Repository $repository) use ($github) { // at most 5 at once
    $github->wait(); // and 10 a second on average
    fetch($repository);
});
```

After a pause, `$burst` calls start at once, then one every `1 / $perSecond` seconds. No timer refills the bucket:
it keeps the time at which it would be full again, and a call compares it with `now()`. It is fair like the mutex it queues on: tokens go in the order
callers came, and only the first one sleeps. A caller cancelled while waiting takes no token, so the next one gets it on time;
`timeout()` around `wait()` bounds how long a call may wait for its turn.

A bucket limits the average, not every window: within any `T` seconds up to `$burst + $perSecond * T` calls start,
so `perSecond: 10, burst: 10` may start 20 within one second. For a server that rejects more than `N` requests
in any `T` seconds, use a sliding window instead, which bounds every interval at full rate:

```php
use Thesis\Async\LocalSlidingWindowRateLimiter;

$api = new LocalSlidingWindowRateLimiter(limit: 10, window: 1); // never 11 starts within one second
```

It keeps the start times of the last `$limit` calls, and a call waits until the oldest of them leaves the window.
It is fair and cancellable in the same way. Prefer the bucket unless the limit is enforced per window:
it spreads the calls evenly, while a window lets `$limit` of them start at once, again and again.

`RateLimiter` is an interface, like `Semaphore`: a limit shared by several processes (in Redis) implements `wait()`
with the same contract, a cancelled wait counts no call.

### Producers and consumers: `Channel`

A bounded queue between fibers that live on their own: request handlers and one writer that stores events in batches,
messages delivered to a subscription, stages of a pipeline. `send()` waits while the buffer is full, `receive()` waits while it is empty:

```php
use Thesis\Async\Channel;
use Thesis\Async\ChannelClosed;
use function Thesis\Async\timeoutOr;

/** @var Channel<Event> */
$events = new Channel(1000);

group(static function (Group $group) use ($events, $server) {
    // spawn(), not daemon(): a daemon is cancelled once the body returns, and the last batch would be lost.
    $group->spawn(static function () use ($events) {
        foreach ($events as $event) { // waits for the first event of a batch without a deadline, ends once closed and drained
            $batch = [$event];

            try {
                // Up to 100 events or 1 second. A value that arrives together with the deadline is not lost.
                timeoutOr(1, null, static function () use ($events, &$batch) {
                    while (\count($batch) < 100) {
                        $batch[] = $events->receive();
                    }
                });
            } catch (ChannelClosed) {
            }

            store($batch);
        }
    });

    try {
        $server->serve(static function (Request $request) use ($events) {
            // ...
            $events->send(new Event($request));
        });
    } finally {
        $events->close(); // the writer drains the buffer, and the join waits for the last batch
    }
});
```

The capacity has no default: how far a slow receiver may fall behind is a decision. `new Channel(0)` is a rendezvous:
`send()` waits until a receiver takes the value. `trySend()` does not wait and never throws: it returns a `SendResult`,
`Sent`, `Full` if there is no room or `Closed` if the channel is closed. It is for metrics and logs that are better
dropped than slowing a request down:

```php
use Thesis\Async\SendResult;

if ($metrics->trySend($sample) !== SendResult::Sent) {
    $dropped++; // full or closed: dropped either way
}
```

`tryReceive()` does not wait either and returns `null` if there is nothing (or the default it is given, when a value may be `null`).
It never throws: on a closed and drained channel it returns the default too, and the end (or the reason of `close()`)
comes from the next `receive()` or `foreach`, so the last batch is not lost.
With it a batch takes what has piled up and goes without waiting for more: large under load, no extra latency when idle:

```php
foreach ($events as $event) {
    $batch = [$event];

    while (\count($batch) < 100 && ($next = $events->tryReceive()) !== null) {
        $batch[] = $next;
    }

    store($batch);
}
```

`count($channel)` is the number of values in the buffer and `$channel->capacity` the number that fit, as `len(ch)` and `cap(ch)` in Go:
for metrics, or to shed load before the buffer is full. Values of waiting senders are not counted.

Nothing is lost or duplicated on cancellation: a `send()` that throws `Cancelled` has not delivered its value,
a `receive()` that throws `Cancelled` has not taken one. Senders and receivers are served in the order they came.

The sender closes the channel. After `close()`, `send()` throws `ChannelClosed` (`trySend()` returns `SendResult::Closed`) and waiting senders are woken with it;
receivers get what is left in the buffer, then `receive()` throws `ChannelClosed` and `foreach` ends. `$isClosed` tells whether
`close()` has been called, not whether the buffer is drained.

A producer in the same group as its consumers just fails: the group cancels them. When they live elsewhere
(the reader of a connection and the subscribers of a protocol client), close the channel with the reason:

```php
try {
    while (true) {
        $messages->send($connection->read());
    }
} catch (\Throwable $error) {
    $messages->close($error); // subscribers drain the buffer, then get $error instead of the end

    throw $error;
}
```

Every receiver gets that same object, as every awaiter of a failed `Deferred` does; senders get `ChannelClosed` with it as the previous exception.
Only the first `close()` counts. A channel cannot be closed with `Cancelled`, for the same reason as `Deferred::fail()`.

There is no `map()` that returns a generator of results as they come: the tasks would outlive the call,
and a consumer that stops iterating would leave them hanging inside a group that never joins.
Handle each result inside the callable of `each()`, or, for one sequential consumer, put it in the same group as the producers:

```php
/** @var Channel<Page> */
$pages = new Channel(10);

group(static function (Group $group) use ($urls, $pages) {
    $group->spawn(static function () use ($urls, $pages) {
        try {
            each($urls, 10, static fn(string $url) => $pages->send(fetch($url)));
        } finally {
            $pages->close();
        }
    });

    foreach ($pages as $page) { // in the order they are fetched
        store($page);
    }
});
```

`group()` returns once both the producers and the consumer are done; if the consumer fails, the producers are cancelled.

Do not wait for several channels with `race()`: if two `receive()` calls get a value in the same tick, `race()` keeps one
and the other is lost. Merge sources by sending into one channel instead.

### Context of a request: `ScopedValue`

A value that reaches every task of a request without being passed as an argument: a trace id, a logger context, a tenant.

```php
use Thesis\Async\ScopedValue;

/** @var ScopedValue<?string> */
$requestId = new ScopedValue(); // once, as a constant or a static property: the object is the key

$requestId->with($request->id, static fn() => all([
    'user' => static fn() => fetchUser($userId),     // deep inside: $logger->info('...', ['request' => $requestId->get()])
    'orders' => static fn() => fetchOrders($userId),
]));
```

There is no `set()`: `with()` binds the value while the callable runs and restores the previous one
whatever it ends with. A task takes the values bound where it is spawned, not where its group was created,
so `with()` around a `spawn()` is visible to the task. Values bound later, in the parent or in the task, do not
reach the other side. `shield()` does not hide values: it only hides cancellation. `onCancel()` callbacks see only defaults.

Where nothing is bound, `get()` returns the default given to the constructor, `null` unless stated otherwise.
A value with a natural default never needs a null check:

```php
$tenant = new ScopedValue(Tenant::system());
```

A bound value is returned as is, even `null`: `with(null, ...)` hides an inherited value from the code inside.
`isBound()` tells a binding from its absence, whatever the value, so a layer can keep what an outer one has bound:

```php
$handle = static fn() => $next($request);

return $requestId->isBound() ? $handle() : $requestId->with(generateId(), $handle);
```

### amphp: `ampCancellable()`

amphp libraries wait through `EventLoop::getSuspension()` directly, so a scope cannot interrupt them by itself.
They take an `Amp\Cancellation` instead, and `ampCancellable()` gives them one tied to the current scope.
It needs `amphp/amp` v3 (any amphp library brings it); the rest of the package does not load it.

```php
use Amp\Cancellation;
use function Thesis\Async\ampCancellable;
use function Thesis\Async\timeout;

$response = timeout(5, static fn() => ampCancellable(
    static fn(Cancellation $cancellation) => $httpClient->request($request, $cancellation),
));
```

If the operation fails with `CancelledException` while the current scope is cancelled, `ampCancellable()` throws `Cancelled`,
as any other wait would, and `timeout()` reports `TimedOut`. A `CancelledException` of the operation's own
(a `TimeoutCancellation` passed alongside, for example) is rethrown as is.
The cancellation is bound to the current scope: do not keep it beyond the callable.

The cancellation costs nothing until amphp subscribes to it. A subscription removed by amphp is never called,
even if the cancellation has already happened, so it cannot wake a fiber that has moved on to another wait.

### Tests on virtual time: `VirtualTime\run()`

Code with long deadlines is hard to test on real time: a heartbeat every 30 seconds, a backoff up to a minute.
`VirtualTime\run()` runs a callable on a virtual clock and a Revolt driver whose timers follow it. The clock starts at the current `now()`
rounded up to a whole second, so a time captured before `run()` (a deadline, a rate limiter) stays valid in it, and stands still
while code runs; when nothing can run, it jumps to the nearest timer. So an hour of waiting is over at once,
timers fire exactly in the order of their deadlines, and durations can be asserted exactly:

```php
use Thesis\Async\VirtualTime;
use function Thesis\Async\now;

VirtualTime\run(static function () {
    $start = now();
    $client = new Client(new AlwaysFailingSocket());

    try {
        $client->connect(); // 5 attempts with a backoff of 1, 2, 4, 8 and 16 seconds
    } catch (ConnectionFailed) {
    }

    assert(now() - $start === 31.0);
});
```

CPU work takes no time on this clock: `VirtualTime\advance($seconds)` moves it forward without yielding,
as busy code would (a negative or `NAN` `$seconds` throws `\ValueError`).
A deadline passes then, but its timer fires only at the next suspension, as after a loop that blocked the event loop.
A deadlock fails at once instead of hanging: there is no timer to jump to, and Revolt ends the loop.
`run()` swaps the driver of the event loop, so it must be called in `{main}`: in a fiber it throws `LogicException`.
The callbacks left for the tick it finishes on return still see its clock.

Streams and signals stay real, on the driver that `EventLoop\DriverFactory` creates (`REVOLT_DRIVER` chooses it),
or on one passed as `run($fn, $driver)`. It must be fresh: not the current driver and with no callbacks,
so nothing from outside runs on virtual time, and it is dropped with whatever `$fn` leaves in it. They are polled on every tick, and only timers are waited for virtually,
so a stream that becomes ready from outside the process may be jumped past.
While `run()` runs, its clock is the clock of the library, bound by `run()` itself rather than found through the driver:
`now()`, deadlines, `remaining()` and the rate limiters follow it even if the driver is decorated meanwhile.
Each `run()` has a clock of its own, so an object that keeps times must not outlive the `run()` it was used in:
a rate limiter shared by two `run()`s one after another, or used again outside, may see a time from the future of its clock.

The tests of this library run this way: a plugin for [Testo](https://github.com/php-testo/testo) in `tests/Testo`,
`VirtualTimePlugin`, runs every test of the suite in its own `run()`, and the `#[VirtualTime]` attribute does the same
for a class or a test. It is not shipped with the package and not part of its API: it is to become a package of its own.

### Your own primitives: `suspend()`

Every wait in the library goes through a single function. Build queues, locks and I/O on it and they are cancellable for free:

```php
use Revolt\EventLoop;
use Thesis\Async\Continuation;
use function Thesis\Async\suspend;

function readable(mixed $stream): void
{
    suspend(static function (Continuation $continuation) use ($stream) {
        $watcher = EventLoop::onReadable($stream, static function () use ($continuation) {
            $continuation->resume();
        });

        // Always called once the wait is over: by the event, by cancellation, by anything.
        return static function () use ($watcher) {
            EventLoop::cancel($watcher);
        };
    });
}
```

Only a closure is taken for `$unregister`: anything else `$register` returns is ignored, so an arrow function may return
whatever its expression gives (a Revolt callback id is a string, and `"die"` is callable).

`Continuation::resume()` returns `false` if the continuation has already fired. A channel uses it to keep a value that was not delivered.
`Continuation::throw()` rejects `Cancelled` with `\ValueError`, for the same reason as `Deferred::fail()`:
the waiting scope is not cancelled.

## How it works

Five small ideas, each built on the previous one.

1. **`Continuation`** is what is left of a single wait: resuming it delivers a value or an error to exactly that wait.
   It is one-shot; late calls are ignored. A timer and a cancellation may race for the same continuation —
   whoever is first wins, the other is a no-op.
2. **Scope** is a node of the cancellation tree: a `cancelled` flag, a parent, children.
   The current scope lives in fiber-local storage, which is why it is never passed as an argument.
   Cancelling a node cancels the whole subtree: the walk marks every node below (a shield stops it)
   and interrupts the waits subscribed to them, and a node created later inherits the mark from its parent,
   so checking for cancellation is a flag read at any depth.
   Only owners cancel scopes: a group, a task handle, a timeout timer. There is no public scope object at all.
3. **`suspend()`** is the only place where a fiber goes to sleep.
   It checks for cancellation, subscribes the continuation to the scope and parks.
   Cancellation is therefore implemented exactly once.
4. **`Group`** starts child fibers in child scopes; `group()` joins them all before returning.
   That join is the structured concurrency guarantee.
5. **`timeout()`** and **`shield()`** are just child scopes: one is cancelled by a timer, the other does not let outer cancellation in.

`Cancelled` carries nothing, and only the library creates it: its constructor is private, since a `Cancelled` thrown
by hand where nothing is cancelled would make code end as cancelled that was not. Whoever catches it tells its own cancellation from a foreign one by the state of the tree:
`timeout()` throws `TimedOut` only if its scope is cancelled and its parent is not.

```
all([user, orders])              orders fails after 50 ms
│
├─ group() creates scope G
│   ├─ spawn(user)   → fiber parked in suspend(), subscribed to G
│   └─ spawn(orders) → fiber parked in suspend(), subscribed to G
│
├─ orders throws RuntimeException
│   └─ G is cancelled → user is woken with Cancelled and unwinds,
│      the trace of that Cancelled points at the wait it interrupted
│
├─ join: no tasks left
└─ group() rethrows RuntimeException
```

## Guarantees

- **No orphans.** `group()` returns or throws only after every task it started has finished. The one exception
  is a deadlock in `{main}` that cancellation cannot break (see Limitations).
- **Cancellation cannot be swallowed.** It is level-triggered: while a scope is cancelled, every wait in it throws `Cancelled` again.
- **Retry loops are safe.** `Cancelled` extends `\Error`, so `catch (\Exception)` does not see it.
- **No lost values.** If an event and a cancellation happen together, the event wins and the value is returned; `Cancelled` is thrown at the next wait.
- **Cancelling a task is not a failure.** `Task::cancel()` stops one task and leaves its group alive.
- **`Cancelled` only where the scope is cancelled.** Awaiting a cancelled task throws `TaskCancelled` instead.
  A `Cancelled` caught elsewhere and rethrown from a task or a group body whose scope is not cancelled
  fails the group as a `LogicException`.
- **No work after cancellation.** A task cancelled before it started never runs: a `race()` won synchronously does not even start the others.
- **No leaked registrations.** The `$unregister` of `suspend()` always runs.

## API

| | |
|---|---|
| `group(callable(Group): T): T` | run a body with a nursery, join all tasks |
| `Group::spawn(callable(): T): Task<T>` | start a task the group waits for |
| `Group::daemon(callable(): T): Task<T>` | start a task cancelled when the rest is done |
| `Group::cancelTasks()` | cancel all tasks and daemons, including later ones, but not the body |
| `Task::await(): T`, `Task::cancel()`, `Task::$isDone` | task handle |
| `all(array<K, callable(): T>): array<K, T>` | all results or the first failure |
| `allSettled(array<K, callable(): T>): array{array<K, T>, array<K, \Throwable>}` | every result and every failure, nothing is cancelled |
| `race(non-empty-array<callable(): T>): T` | first success wins, the rest are cancelled and joined |
| `some(int $count, array<K, callable(): T>): non-empty-array<K, T>` | first `$count` successes win, the rest are cancelled and joined |
| `each(iterable<T>, int $concurrency, callable(T))` | bounded concurrency |
| `map(iterable<K, T>, int $concurrency, callable(T): R): array<K, R>` | bounded concurrency with results |
| `timeout(float $seconds, callable(): T): T` | deadline for a subtree, throws `TimedOut` |
| `timeoutOr(float $seconds, D $default, callable(): T): T\|D` | deadline for a subtree, `$default` once it expires |
| `remaining(): ?float` | seconds left until the nearest deadline, `null` if there is none |
| `now(): float` | monotonic seconds, the clock of deadlines |
| `shield(callable(): T): T` | protect from outer cancellation and outer deadlines |
| `Deferred<T>`: `complete(T): bool`, `fail(\Throwable): bool`, `await(): T`, `$isDone` | one-shot value awaited by many |
| `Mutex extends Semaphore`: `acquire(): Lock`; `LocalMutex`, `LocalSemaphore(int $permits)` | exclusive access, fair |
| `synchronized(Semaphore, callable(): T): T` | run holding a permit, reentrant within a fiber |
| `KeyedMutex extends KeyedSemaphore`: `acquire(int\|string $key): Lock`; `LocalKeyedMutex`, `LocalKeyedSemaphore(int $permits)` | a mutex or a semaphore per key |
| `synchronizedKey(KeyedSemaphore, int\|string $key, callable(): T): T` | run holding a permit of a key, reentrant within a fiber per key |
| `RateLimiter`: `wait(): void`; `LocalTokenBucketRateLimiter(float $perSecond, int $burst)`, `LocalSlidingWindowRateLimiter(int $limit, float $window)` | how often calls start: a token bucket or a sliding window, fair |
| `Channel<T>(int $capacity)`: `send(T)`, `trySend(T): SendResult`, `receive(): T`, `tryReceive(D $default = null): T\|D`, `close(?\Throwable $reason = null)`, `$isClosed`, `count()`, `$capacity`, `foreach` | bounded FIFO queue between fibers, fair |
| `Lock`: `release(): bool`, `$isReleased` | a held permit, one-shot |
| `ScopedValue<T>(T $default = null)`: `get(): T`, `isBound(): bool`, `with(T, callable(): R): R` | a value bound while a callable runs, inherited by the tasks spawned inside |
| `delay(float $seconds)` | cancellable sleep; zero or less waits as zero |
| `awaitSignal(int $signal, int ...$signals): int` | wait for a signal, returns the one received |
| `trapSignals(list<int> $signals, callable(SignalTrap): T): T`; `SignalTrap`: `receive(): int`, `foreach` | catch signals while a callable runs, one pending per signal |
| `checkpoint()` | yield to the event loop and check for cancellation, for code that never waits |
| `isCancelled(): bool` | whether the current scope is cancelled, without waiting |
| `onCancel(callable(): void): \Closure(): void` | call back once the current scope is cancelled, returns unsubscribe |
| `suspend(callable(Continuation<T>): ?\Closure(): void): T` | the suspension point |
| `ampCancellable(callable(Amp\Cancellation): T): T` | run amphp code cancelled with the current scope |
| `suppressed(\Throwable): list<\Throwable>` | failures a group did not rethrow because an earlier one was |
| `Cancelled extends \Error`, `TimedOut`, `TaskCancelled`, `ChannelClosed` | exceptions |
| `SendResult`: `Sent`, `Full`, `Closed` | what `Channel::trySend()` did with the value |
| `VirtualTime\run(callable(): T, ?Driver): T`, `VirtualTime\advance(float $seconds)` | run on a virtual clock that jumps to the next timer; move it as busy code would |

## Limitations

- Code that waits through `EventLoop::getSuspension()` directly (for example, amphp libraries) is not cancelled by a scope
  unless it runs under `ampCancellable()` and is given its cancellation: otherwise it behaves as if shielded until it returns.
- Callbacks registered directly with `EventLoop::queue()`, `defer()` or `delay()` from inside a task, and fibers created by hand,
  run in the root scope: the group neither cancels nor joins them.
- In `{main}`, if the event loop runs dry while a task waits under a shield for something that never comes,
  cancelling cannot help: `group()` gives up joining and rethrows Revolt's "Event loop terminated" error,
  which lists the stuck fibers. Those tasks are orphaned; the alternative is to hang forever.
  Even if the group has already failed with an error of its own, the deadlock error is the one thrown, since the tasks
  were not joined; the group's error is among its `suppressed()` ones.

## TODO

Ideas that are not implemented yet.

- **A PHPStan extension** that maps the array shape of the callables passed to `all()` to the shape of its result.
- **`Time\Duration` of PHP 8.6.** To think over: accept `float|Duration` where a duration is passed in (`delay()`, `timeout()`,
  `timeoutOr()`, the rate limiters), so that a value from the Polling API needs no conversion, while `delay(1)` stays short;
  keep `float` where time comes out (`now()`, `remaining()`), since deadlines are computed with it.
  Then also decide whether to require PHP 8.6 or support 8.5 with a stub for static analysis.
- **A package for `VirtualTimePlugin` and `#[VirtualTime]`**: the Testo plugin lives in `tests/Testo` for now.

## Prior art

[Trio](https://trio.readthedocs.io/) nurseries and cancel scopes, Kotlin `coroutineScope`, Java `StructuredTaskScope`.
