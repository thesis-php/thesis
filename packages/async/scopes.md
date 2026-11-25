# Scopes and cancellation

- `Cancelled extends \Error` and carries nothing. Its constructor is private: only the library creates it,
  through the `@internal` `Cancelled::create()`. Whose cancellation it is, is decided by the state of the scope tree
  where it is caught: `timeout()` throws `TimedOut` only if its scope is cancelled and its parent is not.
  `timeoutOr()` decides the same way, in the shared `Internal\Expired::run()`, never by catching `TimedOut`:
  that would swallow a `TimedOut` of a nested `timeout()`. A deadline `<= 0` cancels the scope at once, without a timer.
  `delay(<= 0)` is `delay(0)`.
- `Cancelled` is thrown only where the current scope is cancelled. Awaiting a cancelled task from a healthy scope
  throws `TaskCancelled` (a regular exception). `Deferred::fail()`, `Channel::close()` and `Continuation::throw()`
  reject `Cancelled` with `\ValueError`; a wait is cancelled only through `ContinuationImpl::interrupt()`. A `Cancelled`
  that escapes a task or a group body whose scope is not cancelled (caught elsewhere and rethrown) fails the group as a
  `LogicException`.
- Only owners cancel scopes: a group (failure, `Group::cancelTasks()`), `Task::cancel()`, the `timeout()` timer.
  There is no public scope object and no public way to cancel an arbitrary scope — do not add one.
  `onCancel()` does not break this: it only observes the cancellation of the current scope.
- `onCancel()` callbacks are queued as microtasks of the loop (`Loop::queue()`), never called inside `Scope::notify()`:
  user code does not run in the middle of the walk. If the scope is cancelled while subscribed, the callback is called
  exactly once and always before the code of the scope ends: `Scope::endCallbacks()` of a cancelled scope calls the ones
  the queue has not reached yet (in the root scope, with no scoped values; a throw goes to the event loop), and the
  queued run is then a no-op. Never after unsubscribing (even if already queued), never after `endCallbacks()` (an ended
  scope stores none), and the root scope stores none. `close()` calls `endCallbacks()`; a group calls it earlier, as
  soon as the code of a body or of a failed task ends and before the failure cancels the group: a scope still needed to
  cancel its children (a body while its tasks run) or a task that failed on its own is not cancelled for its own
  callbacks. They live in their own list: the hot path of `suspend()` is untouched. The root scope stores no waits
  either.
- `Group::join()` is uncancellable. Unstructured spawn (`async()`) is absent on purpose — do not add it.
  `spawn()` on a group whose `group()` has returned throws `LogicException`.
- `Group::cancelTasks()` cancels the tasks and daemons, not the body, and it is sticky: a task spawned afterwards
  never runs. A failure cancels the body too.
- Cancelling a task does not fail its group; a task failure does, and the first failure is the one rethrown.
  A regular task cut short by an outer cancellation makes `group()` throw `Cancelled` after join. One that the group
  has cancelled before (`Task::cancel()`, `cancelTasks()`, the losers of `race()`) is not cut short by an outer
  cancellation that comes while it unwinds: the result of the body stands. Who cancelled first is kept
  in `TaskImpl::$cancelledByHandle` and `GroupImpl::$tasksCancelled`, the flags of the scopes cannot tell.
- Every other failure of a group is recorded by `GroupImpl::fail()` through `Internal\Suppressed`: a `WeakMap` from the
  rethrown error to the list of the others. The rethrown error is never changed (no wrapper, no `previous`); the same
  object is recorded at most once and never under itself; recording an error under one from its own tree drops the edge
  back, so the tree stays acyclic and everything stays reachable from the rethrown error. When a join gives up on a
  deadlock, the group's failure is recorded under the `Deadlock`, unless it is the same deadlock from an earlier round.
- A parent scope holds its children strongly and a child holds its parent: every created `Internal\Scope` must be closed
  with `close()`, or they form a cycle that only the cycle collector frees. A group creates its tasks scope on the
  first spawn, daemon or cancelTasks(), and its daemons scope on the first daemon. Both trace cancellation as the body.
- `Internal\Scope::current()` is on the hot path of every wait: keep it a single `WeakMap` lookup (see the note on
  `CURRENT_SCOPE`). Per-fiber state (`CURRENT_SCOPE`, `CONTEXT`, the locks of `synchronized()`) is a plain
  `WeakMap` keyed by `Internal\fiberKey()`, by the rules of `agents/fibers.md` (`valueGoesAwayWithFiber`).
- Cancellation is propagated eagerly: `Scope::notify()` marks every scope of the non-shielded subtree, and a new scope
  inherits the mark from its parent unless it is a shield. `Scope::$isCancelled` stays a flag read; do not walk the
  tree. One `cancelled` flag per scope is enough because it never goes back; `cancel()` on a cancelled scope is a no-op.
  A wait subscribes its `ContinuationImpl` itself (`Scope::subscribe()`/`unsubscribe()`), without allocating closures.
  A scope holds its first wait in a field. If other waits are already queued, append to the array to keep their
  cancellation order. Against `4c70eb6` on PHP 8.6 arm64, 4096 idle HTTP connections used 1.47 MiB less PHP heap.
  With 4096 runnable tasks, a checkpoint took 4–8% less time in three process pairs. HTTP Arena GET and mixed
  throughput ranges overlapped across three pairs each.
- `ScopedValue`s live outside the scope tree, in `Internal\Context`: an immutable array per fiber, copied by `with()`.
  A task's scope is a child of the group, not of the spawn site, so `GroupImpl::start()` captures the values
  in the spawning fiber and the task runs with them. A shield does not hide values. A task that inherits nothing
  pays for no binding; `suspend()` never reads values.
- In `{main}`, `group()` runs the event loop around itself (`Loop::get()->run()`), so its body and join run in the fiber
  of the loop, with the scope, the scoped values and the locks of `synchronized()` of the caller
  (`Internal\inheritingFiber()`): a `timeout()` or `with()` around it and a reentrant lock must hold as in a fiber.
  Where the loop itself runs (a callback of `onCancel()`, a watchdog) `group()` throws `LogicException` before
  anything starts: its join could not wait, and each round of it would fail the group again. The loop throws
  what escapes to it: such an error fails the group and the join goes on;
  a `Deadlock` fails the group too, but if it comes while the body is already cancelled, the join gives up and rethrows.
  Never let such an exception skip the join or `$bodyScope->close()`.
