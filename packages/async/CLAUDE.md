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
- `Group::join()` is uncancellable. Unstructured spawn (`async()`) is absent on purpose — do not add it.
  `spawn()` on a group whose `group()` has returned throws `LogicException`.
- `Group::cancelTasks()` cancels the tasks and daemons, not the body, and it is sticky: a task spawned afterwards
  never runs. A failure cancels the body too.
- Cancelling a task does not fail its group; a task failure does, and the first failure is the one rethrown.
  A regular task cut short by an outer cancellation makes `group()` throw `Cancelled` after join.
- A task cancelled before it started never runs.
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
- `ScopedValue`s live outside the scope tree, in `Internal\Context`: an immutable array per fiber, copied by `with()`.
  A task's scope is a child of the group, not of the spawn site, so `GroupImpl::start()` captures the values
  in the spawning fiber and the task runs with them. A shield does not hide values. A task that inherits nothing
  pays for no binding; `suspend()` never reads values.
- In `{main}`, `GroupImpl::join()` runs the event loop and Revolt may throw out of it: an `UncaughtThrowable` fails
  the group and the join goes on; a loop that ran dry fails the group too, but if it runs dry while the body is already
  cancelled, the join gives up and rethrows. Never let such an exception skip the join or `$bodyScope->close()`.

## Conventions

- Do not re-check at runtime what static analysis guarantees: a `positive-int` parameter is not validated with a `ValueError`.
  Runtime checks are only for what types cannot express (`Deferred::fail()` rejecting `Cancelled`).
- A callable is named by what runs it: a `$body` is the body of a scope, a `$task` runs in a task,
  a third party's callable is named by its role, and anything else is a `$fn`.
  - `$body`: runs in the current fiber inside a new scope (`group()`, `timeout()`, `shield()`, `Scope::run()`).
    "Body" is a term of the model: the body owns the group.
  - `$task` / `$tasks`: run in a new fiber under its own scope (`Group::spawn()`, `Group::daemon()`, `all()`, `race()`).
  - A role name when a third party calls it at a set moment: `$register`, `$unregister`, `$release`, `$callback`.
  - `$fn`: a plain call with no scope of its own (`synchronized()`, `ScopedValue::with()`, `ampCancellable()`,
    `Context`, `each()`, `map()`).
- Since `$task` is code, a `Task` object is a `$handle` / `$handles`. A scope is `$xxxScope` when its owner
  has a namesake that is not a scope (`$bodyScope`, `$tasksScope`, `$daemonsScope`). A Revolt callback id
  is named by its kind (`$timer`) or `$callbackId`, never `$callback`: that is a closure.
