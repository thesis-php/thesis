# thesis/async

An experimental structured concurrency library on top of `revolt/event-loop`.
The model, the API and the guarantees are described in `README.md` — read it first.

## Invariants that must not be broken

- Every wait goes through `suspend()`. Do not call `EventLoop::getSuspension()` directly in new code
  (the only exception is `Internal\ContinuationImpl::park()`).
- The `$unregister` returned from `suspend()`'s `$register` is always called once the wait is over, whatever ended it.
- Cancellation is level-triggered: while a scope is cancelled, every `suspend()` in it throws `Cancelled`.
- `Cancelled extends \Error` and carries nothing. Whose cancellation it is, is decided by the state of the scope tree
  where it is caught: `timeout()` throws `TimedOut` only if its scope is cancelled and its parent is not.
  `timeoutOr()` decides the same way, in the shared `Internal\Expired::run()`, never by catching `TimedOut`:
  that would swallow a `TimedOut` of a nested `timeout()`. A deadline `<= 0` cancels the scope at once, without a timer.
  A `NAN` deadline or delay throws `\ValueError`; `delay(<= 0)` is `delay(0)`.
- `Cancelled` is thrown only where the current scope is cancelled. Awaiting a cancelled task from a healthy scope
  throws `TaskCancelled` (a regular exception). `Deferred::fail()`, `Channel::close()` and `Continuation::throw()` reject `Cancelled`
  with `\ValueError`; a wait is cancelled only through `ContinuationImpl::interrupt()`.
- Only owners cancel scopes: a group (failure, `Group::cancelTasks()`), `Task::cancel()`, the `timeout()` timer.
  There is no public scope object and no public way to cancel an arbitrary scope — do not add one.
  `onCancel()` does not break this: it only observes the cancellation of the current scope.
- `onCancel()` callbacks are queued through `EventLoop::queue()`, never called inside `Scope::notify()`: user code does not run
  in the middle of the walk. They are called at most once, never after unsubscribing (even if already queued),
  never after `close()`, and the root scope stores none. They live in their own list: the hot path of `suspend()` is untouched.
- `Internal\ScopeCancellation` keeps no state of its own and subscribes only through `Scope::onCancel()`: amphp unsubscribes
  in `finally` and then reuses the fiber's `Suspension` for the next wait, so a callback that runs after `unsubscribe()`
  would wake the wrong wait (this is how a join broke with "Must call suspend() before calling resume()").
- `checkpoint()` must yield to the event loop through `EventLoop::defer()`, not only check a flag:
  CPU-bound code starves timers, so a flag set by a `timeout()` timer would never be seen.
  Not `EventLoop::queue()` either: microtasks run before timers.
  The flag check alone is the internal `Scope::throwIfCancelled()`.
- If the event source fires before the cancellation, `suspend()` returns the value instead of throwing.
- A signal never cancels a scope by itself: a shutdown is a group whose body returns after `awaitSignal()`.
  `trapSignals()` holds referenced Revolt callbacks while its callable runs; `awaitSignal()` is a trap around one `receive()`,
  so a signal outside the wait gets the default action (a second Ctrl+C kills the process).
  `SignalTrap` is not a queue and not a `Channel`: like the OS, at most one pending signal per number, in the order
  they first came, so a repeated signal never pushes out another one. It hands a signal over like `Channel` hands a value:
  only to a receiver whose `resume()` returns `true`, otherwise to the next one or to pending.
- `Group::join()` is uncancellable. Unstructured spawn (`async()`) is absent on purpose — do not add it.
  `spawn()` on a group whose `group()` has returned throws `LogicException`.
- `Group::cancelTasks()` cancels the tasks and daemons, not the body, and it is sticky: a task spawned afterwards
  never runs. A failure cancels the body too.
- Cancelling a task does not fail its group; a task failure does, and the first failure is the one rethrown.
  A regular task cut short by an outer cancellation makes `group()` throw `Cancelled` after join.
- A task cancelled before it started never runs.
- `race()` is `some(1, ...)`. `some()` decides once: after `$count` successes failures are dropped, after more than
  `count($tasks) - $count` failures successes are dropped and every later failure goes to `suppressed()` of the first.
  `allSettled()` never records `Cancelled` as an outcome: it cancels nothing, so a cancelled task means the caller is cancelled.
- Every other failure of a group is recorded by `GroupImpl::fail()` through `Internal\Suppressed`: a `WeakMap` from the rethrown
  error to the list of the others. The rethrown error is never changed (no wrapper, no `previous`); the same object
  is recorded at most once and never under itself. When a join gives up on a deadlock, the group's failure
  is recorded under Revolt's error, unless it is the same deadlock from an earlier round.
- `Channel` hands values over like `LocalSemaphore` hands permits: a value goes to a waiting receiver, or is taken
  from a waiting sender, only if that waiter's `resume()` returns `true`; otherwise it goes to the next one or to the buffer.
  So a `send()` that throws `Cancelled` has not delivered, and a `receive()` that throws `Cancelled` has not taken anything.
  Waiting receivers imply an empty buffer; waiting senders imply a full one (or a rendezvous).
- `each()` runs on `Internal\Workers`: at most `concurrency` tasks, each takes the next item under `synchronized()`
  (a generator may suspend, and another worker must not resume it meanwhile). An item is pulled when it is taken,
  never ahead: `next()` before `valid()`, not after `current()`. `take()` checks cancellation after getting the lock,
  so nothing is pulled after a failure. A new worker is started only when the item just taken leaves no worker free.
  `map()` stays on top of `each()`: `each()` keeps no results, so its memory does not grow with the items, and its keys need not be array keys.
- Revolt callbacks must return `null`: use a closure with a body (`function () { $continuation->resume(); }`), not `fn() => $continuation->resume()`.
- A parent scope holds its children strongly: every created `Internal\Scope` must be closed with `close()`,
  unless it is only reachable through a scope that is closed at the same time (the tasks and daemons scopes of a group).
- `Internal\Scope::current()` is on the hot path of every wait: keep it a single `WeakMap` lookup (see the note on `CURRENT_SCOPE`).
  Per-fiber state (`CURRENT_SCOPE`, `CONTEXT`, the locks of `synchronized()`) is a plain
  `WeakMap` keyed by `Internal\fiberKey()`; call it again instead of keeping the key in a variable across a call
  that may suspend, or a suspended fiber holds itself through its own stack (`valueGoesAwayWithFiber` catches it).
- Cancellation is propagated eagerly: `Scope::notify()` marks every scope of the non-shielded subtree, and a new scope
  inherits the mark from its parent unless it is a shield. `Scope::isCancelled()` stays a flag read; do not walk the tree.
  One `cancelled` flag per scope is enough because it never goes back; `cancel()` on a cancelled scope is a no-op.
  A wait subscribes its `ContinuationImpl` itself (`Scope::subscribe()`/`unsubscribe()`), without allocating closures.
- The deadline is resolved like the cancellation mark: a `Scope` stores the absolute deadline (`hrtime`, `INF` if none),
  the minimum of its own and the parent's, and a shield drops the parent's. `remaining()` reads one field and the clock;
  `suspend()` does not touch the deadline: only the `timeout()` timer enforces it.
- `Internal\Scope::now()` is the only clock of the library (`now()`, deadlines, the rate limiters): never call `hrtime()`
  or `microtime()` elsewhere. Within `VirtualTime\run()`, it reads `Internal\Scope::$virtualClock`, bound by `run()`:
  never find the clock through `EventLoop::getDriver()`, the driver may be decorated. The binding is on `Scope`, not on
  `Internal\VirtualClock`, so that an application that never calls `run()` never loads the class. The clock is not read in `suspend()`.
  The virtual clock starts at `ceil(now())`, not at zero: a time captured before `run()` stays in the past, and whole seconds stay exact.
- A rate limiter keeps absolute times and sleeps once per call, without comparing the clock again after the wake-up:
  a wake-up a hair early (float rounding) must not loop on a delay too small to move the virtual clock.
- `ScopedValue`s live outside the scope tree, in `Internal\Context`: an immutable array per fiber, copied by `with()`.
  A task's scope is a child of the group, not of the spawn site, so `GroupImpl::start()` captures the values
  in the spawning fiber and the task runs with them. A shield does not hide values. A task that inherits nothing
  pays for no binding; `suspend()` never reads values.
- In `{main}`, `GroupImpl::join()` runs the event loop and Revolt may throw out of it: an `UncaughtThrowable` fails
  the group and the join goes on; a loop that ran dry fails the group too, but if it runs dry while the body is already
  cancelled, the join gives up and rethrows. Never let such an exception skip the join or `$bodyScope->close()`.

## Conventions

- Every test runs on virtual time (`VirtualTimePlugin` in `testo.php`, from `tests/Testo`; no attribute is needed): delays are whole seconds
  (`delay(1)`), a wait that only a cancellation ends is `never()` from `tests/functions.php`, durations are asserted exactly
  (`Assert::same(now() - $start, 20.0)`), and busy code is `VirtualTime\advance()`, not a loop on the real clock.
  Keep a real `delay()` where its timer matters: a test of `delay()` itself, or a timer that keeps the loop alive.
  Real time is only for what virtual time cannot model.
- Do not re-check at runtime what static analysis guarantees: a `positive-int` parameter is not validated with a `ValueError`.
  Runtime checks are only for what types cannot express (`Deferred::fail()` rejecting `Cancelled`).
- A callable is named by what runs it: a `$body` is the body of a scope, a `$task` runs in a task,
  a third party's callable is named by its role, and anything else is a `$fn`.
  - `$body`: runs in the current fiber inside a new scope (`group()`, `timeout()`, `shield()`, `Scope::run()`).
    "Body" is a term of the model: the body owns the group.
  - `$task` / `$tasks`: run in a new fiber under its own scope (`Group::spawn()`, `Group::daemon()`, `all()`, `allSettled()`, `race()`, `some()`).
  - A role name when a third party calls it at a set moment: `$register`, `$unregister`, `$release`, `$callback`.
  - `$fn`: a plain call with no scope of its own (`synchronized()`, `ScopedValue::with()`, `ampCancellable()`,
    `Context`, `each()`, `map()`).
- Since `$task` is code, a `Task` object is a `$handle` / `$handles`. A scope is `$xxxScope` when its owner
  has a namesake that is not a scope (`$bodyScope`, `$tasksScope`, `$daemonsScope`). A Revolt callback id
  is named by its kind (`$timer`) or `$callbackId`, never `$callback`: that is a closure.
