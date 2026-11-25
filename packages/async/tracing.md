# Tracing

- Tracing (`Trace\observe()`) only observes and costs nothing without an observation: every hook is
  `Scope::$tracer?->...`, which evaluates no arguments while it is null, and `suspend()` reads `Scope::$tracer`
  before the park and after cleanup. An observer attached during a wait sees its resumption, with the scope id
  assigned by the tracer that is active then. `Trace\ObserverDetached` goes to the dispatcher whose last observation
  ended. It has no scope and is separate from the scope events in `Trace\Event`.
  A hook goes where the outcome is decided (the task callback of `GroupImpl::start()`, after `join()`, `Expired::run()`,
  `shield()`), never into `Scope::run()` or `ContinuationImpl`. `ScopeCancelled` is emitted after `notify()`, so the
  listener never runs in the middle of the walk, and the tracer finds the scopes it reached by `$isCancelled`
  among its pending ones instead of walking the tree again. An event that no listener of the attached dispatchers
  gets is not built (`Tracer::listens()`, cached until the dispatchers change), but its scope is numbered anyway:
  the numbers never depend on what is listened to. Every `Scope::cancel()` names its `CancelCause` and the
  scope that cancels. The tasks and daemons scopes of a group get the number of its body: they are not in the model.
  The events hold no `Scope`: there is no public scope object, only its number.
  The name of a task (`spawn()`/`daemon()`, the key in `all()`, `allSettled()`, `some()`, `race()`), the name
  of a group (`group()` only: the group of `all()`, `race()` and the others is named by its function and its tasks
  by their keys, a `name:` of each would widen every signature for a rare case) and its function go to the tracer
  only, never into `Scope` or `TaskImpl`: a name in the scope would cost memory per task without an observation. A
  worker of `each()`/`map()` has no name, its items are many. `allSettled()`, `some()` and `race()` run each task in a
  wrapper that catches its outcome: the wrapper hands it to `Tracer::settled()`, and `Ended` of the task reports it
  instead of what the wrapper returns (`null`, a box). A new wrapper of a task does the same.
  `TaskCreated::$task` is always the code of the user, never a closure of the library: a wrapper and a worker
  of `each()`/`map()` (its `$fn`, called on each item) are spawned by `GroupImpl::spawnWrapped()` with the callable
  of the user. So its type is `callable(never): mixed`: to show, not to call.
  The function is passed as `__FUNCTION__`, never as a literal: `'Thesis\Async\group'` is the interface `Group`
  to Rector (it rewrites it to `group::class`) and to PHPStan, class names being case-insensitive. A new way to create a
  scope, to wait or to cancel adds its event, and `TraceChecker` (whole traces on the generated programs) checks it.
  A new event goes into `@phpstan-sealed` of `Trace\Event`: PHPStan rejects a class of it missing there, and then
  points at every `match` over the events that does not handle it (they have no `default`, `php.md`).

## Decisions

- Tracing is one stream of event objects to a `Thesis\Dispatcher`, not a method per event: a new event is a new
  class, which breaks no listener at runtime, and the dispatcher tells which events nobody gets, so the tracer
  skips the backtraces of a wait for a listener of cancellations alone. Rejected: an `Observer` interface of our own,
  it routed by `instanceof` in every observer and could not tell the tracer what to skip.
  The events are a closed set (`@phpstan-sealed`): only the library creates them, and a tool that handles each one
  learns of a new one from PHPStan. Rejected: a list of the classes in a constant, a second list next to the sealed.
  Rejected: hooks on `Scope::__construct()` (the kind of a scope is known only
  where it is created), events from inside `notify()` (user code in the middle of the walk), a full backtrace per wait (`caller()` of
  thesis/source-location takes the first frame outside this library, and names the call that waits).
