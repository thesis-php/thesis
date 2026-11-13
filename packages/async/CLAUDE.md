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
  throws `TaskCancelled` (a regular exception).
- Only owners cancel scopes: a group (failure, `Group::cancel()`), `Task::cancel()`, the `timeout()` timer.
  There is no public scope object and no public way to cancel an arbitrary scope — do not add one.
- `checkpoint()` must yield to the event loop through `EventLoop::defer()`, not only check a flag:
  CPU-bound code starves timers, so a flag set by a `timeout()` timer would never be seen.
  Not `EventLoop::queue()` either: microtasks run before timers.
- If the event source fires before the cancellation, `suspend()` returns the value instead of throwing.
- `Group::join()` is uncancellable. Unstructured spawn (`async()`) is absent on purpose — do not add it.
  `spawn()` on a group whose `group()` has returned throws `LogicException`.
- `Group::cancel()` cancels the tasks, not the body. A failure cancels the body too.
- Cancelling a task does not fail its group; a task failure does, and the first failure is the one rethrown.
  A regular task cut short by an outer cancellation makes `group()` throw `Cancelled` after join.
- A task cancelled before it started never runs.
- Revolt callbacks must return `null`: use a closure with a body (`function () { $continuation->resume(); }`), not `fn() => $continuation->resume()`.
- A parent scope holds its children strongly: every created `Internal\Scope` must be closed with `close()`.
- `Internal\Scope::current()` is on the hot path of every wait: keep it a single `WeakMap` lookup (see the note there).
