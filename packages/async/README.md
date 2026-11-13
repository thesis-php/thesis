# Thesis Async

Structured concurrency for PHP fibers on top of [Revolt](https://revolt.run): cancellation is a property of the task tree, not a function parameter.

> **Experimental.** The API is unstable and there is no test suite yet.

## Installation

```shell
composer require thesis/async
```

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

`all()`, `race()`, `map()` and `each()` cover most cases without a task handle. All of them are built on `group()`.

### Tasks: `group()`

When tasks are started dynamically or a background task is needed, use a group directly.
A concurrent task can only be started inside a group, and `group()` does not return until every task has finished.
There is no way to leak a running fiber.

The group is passed explicitly on purpose. Only a function that takes a `Group` can leave work running after it returns,
and its signature says so.

Start early, decide later. The body works concurrently with its tasks and may cancel the ones it no longer needs:

```php
use Thesis\Async\Group;
use function Thesis\Async\group;

$page = group(static function (Group $group) use ($userId) {
    $avatar = $group->spawn(static fn() => fetchAvatar($userId)); // starts right away
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

`Group::cancel()` cancels every task, including the ones started later, but not the body: the body owns the group,
and `group()` returns its result. Work that has to be stoppable this way belongs in a task.

Awaiting a cancelled task throws `TaskCancelled`, a regular exception: the awaiting code is not cancelled,
it has just lost a dependency.

If an outer cancellation cuts a task short, `group()` throws `Cancelled` even if the body never awaited that task:
a group never pretends that its work is done.

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
and an outer cancellation always outranks a deadline.

### First success wins: `race()`

The losers are cancelled and joined. A failure does not end the race while another contender can still win;
if every contender fails, the first failure is rethrown.

```php
use function Thesis\Async\delay;
use function Thesis\Async\race;

// Hedged request: ask the replica only if the primary is slow.
$value = race(
    static fn() => read('primary', $key),
    static function () use ($key) {
        delay(0.05);

        return read('replica', $key);
    },
);
```

### Bounded concurrency: `each()` and `map()`

```php
use function Thesis\Async\each;
use function Thesis\Async\map;

each($urls, crawl(...), concurrency: 20);

$pages = map($urls, static fn(string $url) => fetch($url), concurrency: 20); // keys and order of $urls
```

The items are iterated in the calling fiber, so the iterable may itself wait, for example, for the next page of an API.

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

### Your own primitives: `suspend()`

Every wait in the library goes through a single function. Build channels, locks and I/O on it and they are cancellable for free:

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

`Continuation::resume()` returns `false` if the continuation has already fired. A channel uses it to keep a value that was not delivered.

## How it works

Five small ideas, each built on the previous one.

1. **`Continuation`** is what is left of a single wait: resuming it delivers a value or an error to exactly that wait.
   It is one-shot; late calls are ignored. A timer and a cancellation may race for the same continuation —
   whoever is first wins, the other is a no-op.
2. **Scope** is a node of the cancellation tree: a `cancelled` flag, a parent, children.
   The current scope lives in fiber-local storage, which is why it is never passed as an argument.
   Cancelling a node cancels the whole subtree. Only owners cancel scopes: a group, a task handle, a timeout timer.
   There is no public scope object at all.
3. **`suspend()`** is the only place where a fiber goes to sleep.
   It checks for cancellation, subscribes the continuation to the scope and parks.
   Cancellation is therefore implemented exactly once.
4. **`Group`** starts child fibers in child scopes; `group()` joins them all before returning.
   That join is the structured concurrency guarantee.
5. **`timeout()`** and **`shield()`** are just child scopes: one is cancelled by a timer, the other does not let outer cancellation in.

`Cancelled` carries nothing. Whoever catches it tells its own cancellation from a foreign one by the state of the tree:
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

- **No orphans.** `group()` returns only after every task it started has finished.
- **Cancellation cannot be swallowed.** It is level-triggered: while a scope is cancelled, every wait in it throws `Cancelled` again.
- **Retry loops are safe.** `Cancelled` extends `\Error`, so `catch (\Exception)` does not see it.
- **No lost values.** If an event and a cancellation happen together, the event wins and the value is returned; `Cancelled` is thrown at the next wait.
- **Cancelling a task is not a failure.** `Task::cancel()` stops one task and leaves its group alive.
- **`Cancelled` only where the scope is cancelled.** Awaiting a cancelled task throws `TaskCancelled` instead.
- **No work after cancellation.** A task cancelled before it started never runs: a `race()` won synchronously does not even start the others.
- **No leaked registrations.** The `$unregister` of `suspend()` always runs.

## API

| | |
|---|---|
| `group(callable(Group): T): T` | run a body with a nursery, join all tasks |
| `Group::spawn(callable(): T): Task<T>` | start a task the group waits for |
| `Group::daemon(callable(): T): Task<T>` | start a task cancelled when the rest is done |
| `Group::cancel()` | cancel all tasks, but not the body |
| `Task::await(): T`, `Task::cancel()`, `Task::$isDone` | task handle |
| `all(array<K, callable(): T>): array<K, T>` | all results or the first failure |
| `race(callable(): T ...): T` | first success wins, the rest are cancelled and joined |
| `each(iterable<K, T>, callable(T, K), int $concurrency)` | bounded concurrency |
| `map(iterable<K, T>, callable(T, K): R, int $concurrency): array<K, R>` | bounded concurrency with results |
| `timeout(float $seconds, callable(): T): T` | deadline for a subtree, throws `TimedOut` |
| `shield(callable(): T): T` | protect from outer cancellation |
| `Deferred<T>`: `complete(T): bool`, `fail(\Throwable): bool`, `await(): T`, `$isDone` | one-shot value awaited by many |
| `delay(float $seconds)` | cancellable sleep |
| `checkpoint()` | yield to the event loop and check for cancellation, for code that never waits |
| `suspend(callable(Continuation<T>): ?callable(): void): T` | the suspension point |
| `Cancelled extends \Error`, `TimedOut`, `TaskCancelled` | exceptions |

## Limitations

- If several tasks of a group fail, only the first failure is reported.
- `each()` and `map()` start a fiber per item, not per worker.
- Code that waits through `EventLoop::getSuspension()` directly (for example, amphp libraries) is not cancelled by a scope: it behaves as if shielded until it returns.
- A wait costs about three times more than a bare Revolt suspension (≈3.3 µs vs ≈1 µs in a micro-benchmark).

## Prior art

[Trio](https://trio.readthedocs.io/) nurseries and cancel scopes, Kotlin `coroutineScope`, Java `StructuredTaskScope`.
Design sketches of this and several alternative models live in [`ideas/`](ideas).
