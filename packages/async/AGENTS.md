# thesis/async

An experimental structured concurrency library on an event loop of its own (`Internal\Loop` on `Io\Poll` of PHP 8.6).
The model, the API and the guarantees are described in `README.md`.

Read the topics for the behaviour your change affects, including changes to its callers:

- [Scopes and cancellation](scopes.md) for scope ownership, cancellation, group failure, joining and inherited values.
- [Coordination and worker primitives](primitives.md) for handoff, locking, shared initialization,
  task combinators and concurrent iteration.
- [Clocks and pacing](clocks.md) for deadlines, virtual clocks, rate limits, fairness and backoff.
- [The event loop](event-loop.md) for scheduling, timers, stream or signal waits, and virtual time.
- [Tracing](tracing.md) when changing tracing itself or how tasks, scopes, waits or cancellations emit events.
- [Testing the async runtime](testing.md) when writing or changing package tests.

## Invariants that must not be broken

- Every wait goes through `suspend()` or parks an `Internal\ContinuationImpl` registered in the loop
  (`delay()`, `checkpoint()`, `StreamWatcher`). Nothing else calls `\Fiber::suspend()`. Those of the loop take
  the steps of `suspend()` in its order (the check of cancellation, the registration, the subscription, the park
  with a trace event before and after, the unregistration in `finally`) without its closures, a fifth of such a wait
  (`delay()`, `checkpoint()`, `StreamWatcher::wait()`). A wait on a queue
  of the library (`Deferred`, `Channel`, the semaphores) still goes through `suspend()`: `ideas/loop.md`.
- The `$unregister` returned from `suspend()`'s `$register` is always called once the wait is over, whatever ended it.
  Only a `\Closure` is called: an arrow function that resumes at once returns what it has called, and a string
  may be callable (`"die"`).
- Cancellation is level-triggered: while a scope is cancelled, every `suspend()` in it throws `Cancelled`.
- `checkpoint()` must yield to the event loop through `Loop::defer()`, not only check a flag:
  CPU-bound code starves timers, so a flag set by a `timeout()` timer would never be seen.
  Not `Loop::queue()` either: microtasks run before timers.
  The flag check alone is the internal `Scope::throwIfCancelled()`.
- If the event source fires before the cancellation, `suspend()` returns the value instead of throwing.
- Signal subscriptions are counted per loop, signal and closure object. Several groups can share one handler
  and release their subscriptions independently. The loop calls the shared handler once per delivery.
- A signal never cancels a scope by itself: a shutdown is a group whose body returns after `awaitSignal()`.
  `awaitShutdownSignal()` checks for ext-pcntl before reading SIGINT and SIGTERM because the extension defines them.
  `trapSignals()` holds handlers of the loop (`Loop::onSignal()`, installed at once) while its callable runs, and needs
  ext-pcntl (`LogicException` without it, which is why the package only suggests it); `awaitSignal()` is
  a trap around one `receive()`, so a signal outside the wait gets the default action (a second Ctrl+C kills the
  process). `SignalTrap` is not a queue and not a `Channel`: like the OS, at most one pending signal per number, in the
  order they first came, so a repeated signal never pushes out another one. It hands a signal over like `Channel` hands
  a value: only to a receiver whose `resume()` returns `true`, otherwise to the next one or to pending.
- A task cancelled before it started never runs.
- Install inherited scoped values before dispatching task lifecycle events and restore them after cleanup.
  A trace listener can depend on the same scoped value as the task, including a logger feedback guard.
- `StreamWatcher` is an object owned with the stream, not free functions like `awaitReadable($stream)`: the owner must
  be able to end every wait before `fclose()` (the kernel drops a closed descriptor, and nothing would wake its
  waits). `close()` resumes the waits with `false` and cancels their watchers; a wait after `close()` returns `false`
  without suspending or a cancellation check, like `receive()` of a closed channel. Its `delay()` is there for the
  owner's backoff (an accept loop), which a closed stream must end too; it follows `delay()`: `<= 0` is zero.

## Decisions

- Rejected for HTTP throughput: appending checkpoints to a packed deferred queue and waking them directly.
  Against `7eaf938` with 4096 connections on one server vCPU, GET gained throughput in two of three pairs,
  but the HTTP Arena H1 mix lost 5.4%, gained 8.2% and lost 5.6%. Median mixed p99 fell from 183.5 to 151.8 ms.

## Conventions

- A callable is named by what runs it: a `$body` is the body of a scope, a `$task` runs in a task,
  a third party's callable is named by its role, and anything else is a `$fn`.
  - `$body`: runs in the current fiber inside a new scope (`group()`, `timeout()`, `shield()`, `Scope::run()`).
    "Body" is a term of the model: the body owns the group.
  - `$task` / `$tasks`: run in a new fiber under its own scope (`Group::spawn()`, `Group::daemon()`, `all()`,
    `allSettled()`, `race()`, `some()`).
  - A role name when a third party calls it at a set moment: `$register`, `$unregister`, `$release`, `$callback`.
  - `$fn`: a plain call with no scope of its own (`synchronized()`, `ScopedValue::with()`,
    `Context`, `each()`, `map()`).
- Since `$task` is code, a `Task` object is a `$handle` / `$handles`. A scope is `$xxxScope` when its owner
  has a namesake that is not a scope (`$bodyScope`, `$tasksScope`, `$daemonsScope`). A timer of the loop
  is `$timer`, never `$callback`: that is a closure.
