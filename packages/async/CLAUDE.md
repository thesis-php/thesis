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
  throws `TaskCancelled` (a regular exception). `Deferred::fail()` and `Continuation::throw()` reject `Cancelled`
  with `\ValueError`; a wait is cancelled only through `ContinuationImpl::interrupt()`.
- Only owners cancel scopes: a group (failure, `Group::cancelTasks()`), `Task::cancel()`, the `timeout()` timer.
  There is no public scope object and no public way to cancel an arbitrary scope — do not add one.
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
- Revolt callbacks must return `null`: use a closure with a body (`function () { $continuation->resume(); }`), not `fn() => $continuation->resume()`.
- A parent scope holds its children strongly: every created `Internal\Scope` must be closed with `close()`,
  unless it is only reachable through a scope that is closed at the same time (the tasks and daemons scopes of a group).
- `Internal\Scope::current()` is on the hot path of every wait: keep it a single `WeakMap` lookup (see the note on `FiberLocal`).
- Cancellation is propagated eagerly: `Scope::notify()` marks every scope of the non-shielded subtree, and a new scope
  inherits the mark from its parent unless it is a shield. `Scope::isCancelled()` stays a flag read; do not walk the tree.
  One `cancelled` flag per scope is enough because it never goes back; `cancel()` on a cancelled scope is a no-op.
  A wait subscribes its `ContinuationImpl` itself (`Scope::subscribe()`/`unsubscribe()`), without allocating closures.
- In `{main}`, `GroupImpl::join()` runs the event loop and Revolt may throw out of it: an `UncaughtThrowable` fails
  the group and the join goes on; a loop that ran dry fails the group too, but if it runs dry while the body is already
  cancelled, the join gives up and rethrows. Never let such an exception skip the join or `$bodyScope->close()`.

## Conventions

- Do not re-check at runtime what static analysis guarantees: a `positive-int` parameter is not validated with a `ValueError`.
  Runtime checks are only for what types cannot express (`Deferred::fail()` rejecting `Cancelled`).
