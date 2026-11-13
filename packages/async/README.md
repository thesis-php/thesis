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

### Tasks: `group()`

A concurrent task can only be started inside a group, and `group()` does not return until every task has finished.
There is no way to leak a running fiber.

```php
use Thesis\Async\Group;
use function Thesis\Async\group;

$report = group(static function (Group $group) {
    $sales = $group->go(static fn() => loadSales());
    $costs = $group->go(static fn() => loadCosts());

    return new Report($sales->await(), $costs->await());
});
```

A background task that lives exactly as long as the work it accompanies:

```php
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

Nested timeouts do not confuse each other: an inner `timeout()` never reports an outer deadline as its own.

### First result wins: `race()`

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

### Bounded concurrency: `each()`

```php
use function Thesis\Async\each;

each($urls, concurrency: 20, fn: static fn(string $url) => crawl($url));
```

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

### Your own primitives: `suspend()`

Every wait in the library goes through a single function. Build channels, locks and I/O on it and they are cancellable for free:

```php
use Revolt\EventLoop;
use Thesis\Async\Waker;
use function Thesis\Async\suspend;

function readable(mixed $stream): void
{
    suspend(static function (Waker $waker) use ($stream) {
        $watcher = EventLoop::onReadable($stream, static function (string $watcher) use ($waker) {
            EventLoop::cancel($watcher);
            $waker->resume();
        });

        // Called only if the wait is interrupted by cancellation.
        return static function () use ($watcher) {
            EventLoop::cancel($watcher);
        };
    });
}
```

## How it works

Five small ideas, each built on the previous one.

1. **`Waker`** is a one-shot alarm for a single wait. It fires once; late calls are ignored.
   A timer and a cancellation may race for the same waker — whoever is first wins, the other is a no-op.
2. **`Scope`** is a node of the cancellation tree: a `cancelled` flag, a parent, children.
   The current scope lives in fiber-local storage, which is why it is never passed as an argument.
   Cancelling a node cancels the whole subtree.
3. **`suspend()`** is the only place where a fiber goes to sleep.
   It checks for cancellation, subscribes the waker to the scope and parks.
   Cancellation is therefore implemented exactly once.
4. **`Group`** starts child fibers in child scopes; `group()` joins them all before returning.
   That join is the structured concurrency guarantee.
5. **`timeout()`** and **`shield()`** are just child scopes: one is cancelled by a timer, the other does not let outer cancellation in.

```
all([user, orders])              orders fails after 50 ms
│
├─ group() creates scope G
│   ├─ go(user)   → fiber parked in suspend(), subscribed to G
│   └─ go(orders) → fiber parked in suspend(), subscribed to G
│
├─ orders throws RuntimeException
│   └─ G is cancelled → user is woken with Cancelled and unwinds
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

## API

| | |
|---|---|
| `group(callable(Group): T): T` | run a body with a nursery, join all tasks |
| `Group::go(callable(): T): Task<T>` | start a task the group waits for |
| `Group::daemon(callable(): T): Task<T>` | start a task cancelled when the rest is done |
| `Group::cancel()` | cancel the body and all tasks |
| `Task::await(): T`, `Task::cancel()`, `Task::$isDone` | task handle |
| `all(array<K, callable(): T>): array<K, T>` | all results or the first failure |
| `race(callable(): T ...): T` | first to finish wins, losers are cancelled and joined |
| `each(iterable<T>, int $concurrency, callable(T))` | bounded concurrency |
| `timeout(float $seconds, callable(): T): T` | deadline for a subtree, throws `TimedOut` |
| `shield(callable(): T): T` | protect from outer cancellation |
| `delay(float $seconds)` | cancellable sleep |
| `suspend(callable(Waker<T>): ?callable(): void): T` | the suspension point |
| `currentScope(): Scope`, `Scope::$isCancelled`, `Scope::checkpoint()`, `Scope::cancel()`, `Scope::onCancel()` | direct access to the tree |

## Limitations

- If several tasks of a group fail, only the first failure is reported.
- The iterable passed to `each()` must not suspend while producing an item.
- Code that waits through `EventLoop::getSuspension()` directly (for example, amphp libraries) is not cancelled by a scope: it behaves as if shielded until it returns.
- A wait costs about three times more than a bare Revolt suspension (≈3.3 µs vs ≈1 µs in a micro-benchmark).

## Prior art

[Trio](https://trio.readthedocs.io/) nurseries and cancel scopes, Kotlin `coroutineScope`, Java `StructuredTaskScope`.
Design sketches of this and several alternative models live in [`ideas/`](ideas).
