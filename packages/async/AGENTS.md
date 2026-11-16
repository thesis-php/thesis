# thesis/async

An experimental structured concurrency library on top of `revolt/event-loop`.
The model, the API and the guarantees are described in `README.md` — read it first.
Design sketches of this and several alternative models live in `docs/ideas/async/` of the monorepo.

This file is for agents changing this package. It is not loaded while an agent works in another package,
so how to *use* this package belongs in `README.md` or in a skill under `skills/<skill-name>/SKILL.md`.

## Invariants that must not be broken

- Every wait goes through `suspend()`. Do not call `EventLoop::getSuspension()` directly in new code
  (the only exception is `Internal\ContinuationImpl::park()`).
- The `$unregister` returned from `suspend()`'s `$register` is always called once the wait is over, whatever ended it.
  Only a `\Closure` is called: a Revolt callback id is a string and may be callable (`"die"`).
- Cancellation is level-triggered: while a scope is cancelled, every `suspend()` in it throws `Cancelled`.
- `Cancelled extends \Error` and carries nothing. Its constructor is private: only the library creates it,
  through the `@internal` `Cancelled::create()`. Whose cancellation it is, is decided by the state of the scope tree
  where it is caught: `timeout()` throws `TimedOut` only if its scope is cancelled and its parent is not.
  `timeoutOr()` decides the same way, in the shared `Internal\Expired::run()`, never by catching `TimedOut`:
  that would swallow a `TimedOut` of a nested `timeout()`. A deadline `<= 0` cancels the scope at once, without a timer.
  `delay(<= 0)` is `delay(0)`.
- `Cancelled` is thrown only where the current scope is cancelled. Awaiting a cancelled task from a healthy scope
  throws `TaskCancelled` (a regular exception). `Deferred::fail()`, `Channel::close()` and `Continuation::throw()` reject `Cancelled`
  with `\ValueError`; a wait is cancelled only through `ContinuationImpl::interrupt()`. A `Cancelled` that escapes a task
  or a group body whose scope is not cancelled (caught elsewhere and rethrown) fails the group as a `LogicException`.
- Only owners cancel scopes: a group (failure, `Group::cancelTasks()`), `Task::cancel()`, the `timeout()` timer.
  There is no public scope object and no public way to cancel an arbitrary scope — do not add one.
  `onCancel()` does not break this: it only observes the cancellation of the current scope.
- `onCancel()` callbacks are queued through `EventLoop::queue()`, never called inside `Scope::notify()`: user code does not run
  in the middle of the walk. If the scope is cancelled while subscribed, the callback is called exactly once and always
  before the code of the scope ends: `Scope::endCallbacks()` of a cancelled scope calls the ones the queue has not reached
  yet (in the root scope, with no scoped values; a throw goes to the event loop), and the queued run is then a no-op.
  Never after unsubscribing (even if already queued), never after `endCallbacks()` (an ended scope stores none),
  and the root scope stores none. `close()` calls `endCallbacks()`; a group calls it earlier, as soon as the code
  of a body or of a failed task ends and before the failure cancels the group: a scope still needed to cancel its
  children (a body while its tasks run) or a task that failed on its own is not cancelled for its own callbacks.
  They live in their own list: the hot path of `suspend()` is untouched. The root scope stores no waits either.
- `Internal\ScopeCancellation` keeps no cancellation state of its own (only the unsubscribe functions by id)
  and subscribes only through `Scope::onCancel()`: amphp unsubscribes
  in `finally` and then reuses the fiber's `Suspension` for the next wait, so a callback that runs after `unsubscribe()`
  would wake the wrong wait (this is how a join broke with "Must call suspend() before calling resume()").
- `checkpoint()` must yield to the event loop through `EventLoop::defer()`, not only check a flag:
  CPU-bound code starves timers, so a flag set by a `timeout()` timer would never be seen.
  Not `EventLoop::queue()` either: microtasks run before timers.
  The flag check alone is the internal `Scope::throwIfCancelled()`.
- If the event source fires before the cancellation, `suspend()` returns the value instead of throwing.
- A signal never cancels a scope by itself: a shutdown is a group whose body returns after `awaitSignal()`.
  `trapSignals()` holds referenced Revolt callbacks while its callable runs, and calls it only after a `checkpoint()`:
  Revolt installs a signal handler at the next tick, and a signal before it gets the default action; `awaitSignal()` is a trap around one `receive()`,
  so a signal outside the wait gets the default action (a second Ctrl+C kills the process).
  `SignalTrap` is not a queue and not a `Channel`: like the OS, at most one pending signal per number, in the order
  they first came, so a repeated signal never pushes out another one. It hands a signal over like `Channel` hands a value:
  only to a receiver whose `resume()` returns `true`, otherwise to the next one or to pending.
- `Group::join()` is uncancellable. Unstructured spawn (`async()`) is absent on purpose — do not add it.
  `spawn()` on a group whose `group()` has returned throws `LogicException`.
- `Group::cancelTasks()` cancels the tasks and daemons, not the body, and it is sticky: a task spawned afterwards
  never runs. A failure cancels the body too.
- Cancelling a task does not fail its group; a task failure does, and the first failure is the one rethrown.
  A regular task cut short by an outer cancellation makes `group()` throw `Cancelled` after join. One that the group
  has cancelled before (`Task::cancel()`, `cancelTasks()`, the losers of `race()`) is not cut short by an outer
  cancellation that comes while it unwinds: the result of the body stands. Who cancelled first is kept
  in `TaskImpl::$cancelledByHandle` and `GroupImpl::$tasksCancelled`, the flags of the scopes cannot tell.
- A task cancelled before it started never runs.
- `race()` is `some(1, ...)`. `some()` decides once: after `$count` successes failures are dropped, after more than
  `count($tasks) - $count` failures successes are dropped and every later failure goes to `suppressed()` of the first.
  `allSettled()` never records `Cancelled` as an outcome: it cancels nothing, so a cancelled task means the caller is cancelled.
- Every other failure of a group is recorded by `GroupImpl::fail()` through `Internal\Suppressed`: a `WeakMap` from the rethrown
  error to the list of the others. The rethrown error is never changed (no wrapper, no `previous`); the same object
  is recorded at most once and never under itself; recording an error under one from its own tree drops the edge back,
  so the tree stays acyclic and everything stays reachable from the rethrown error. When a join gives up on a deadlock, the group's failure
  is recorded under Revolt's error, unless it is the same deadlock from an earlier round.
- `Channel` hands values over like `LocalSemaphore` hands permits: a value goes to a waiting receiver, or is taken
  from a waiting sender, only if that waiter's `resume()` returns `true`; otherwise it goes to the next one or to the buffer.
  So a `send()` that throws `Cancelled` has not delivered, and a `receive()` that throws `Cancelled` has not taken anything.
  Waiting receivers imply an empty buffer; waiting senders imply a full one (or a rendezvous).
- `each()` and `map()` run on `Internal\Workers`: at most `concurrency` tasks, each takes the next item under `synchronized()`
  (a generator may suspend, and another worker must not resume it meanwhile); an array cannot suspend, so it is taken
  without the lock. An item is pulled when it is taken, never ahead: `next()` before `valid()`, not after `current()`.
  Taking checks cancellation first (after getting the lock), so nothing is pulled after a failure.
  A new worker is started only when the item just taken leaves no worker free.
  The callable gets the value only (`$fn($value)`, so an internal function fits); only `map()` uses the key, for its result.
  `each()` keeps no results, so its memory does not grow with the items, and its keys need not be array keys.
- `StreamWatcher` is an object owned with the stream, not free functions like `awaitReadable($stream)`: the owner must be
  able to end every wait before `fclose()` (a Revolt watcher of a closed stream is an error, the fiber would never wake up).
  `close()` resumes the waits with `false` and cancels their watchers; a wait after `close()` returns `false` without
  suspending or a cancellation check, like `receive()` of a closed channel. Its `delay()` is there for the owner's backoff
  (an accept loop), which a closed stream must end too; it follows `delay()`: `<= 0` is zero.
- `Pacer` is separate from `StreamWatcher`: fairness of a fast path has nothing to do with streams
  (a hot channel needs it too). It counts calls per tick and is reset by an `EventLoop::defer()` registered
  by the first call of the tick; past the limit it calls `checkpoint()` *before* the operation,
  so a `Cancelled` from it means the operation did not happen. The count is not reset after that yield: the tick's defer
  has done it, and other callers of a shared pacer may have counted since (a reset let N fibers pass N × `$perTick`).
  Only if no reset has happened during the yield, the defer was dropped with its driver, and the yield resets the count:
  otherwise a pacer that outlives a driver (`stdout()` of thesis/io across `VirtualTime\run()`) yields on every call.
  Calls under the limit do not check cancellation: a fast path stays a counter increment.
- Revolt callbacks must return `null`: use a closure with a body (`function () { $continuation->resume(); }`), not `fn() => $continuation->resume()`.
- A parent scope holds its children strongly and a child holds its parent: every created `Internal\Scope` must be closed
  with `close()`, the tasks and daemons scopes of a group too, or they form a cycle that only the cycle collector frees.
- `Internal\Scope::current()` is on the hot path of every wait: keep it a single `WeakMap` lookup (see the note on `CURRENT_SCOPE`).
  Per-fiber state (`CURRENT_SCOPE`, `CONTEXT`, the locks of `synchronized()`) is a plain
  `WeakMap` keyed by `Internal\fiberKey()`; call it again instead of keeping the key in a variable across a call
  that may suspend, or a suspended fiber holds itself through its own stack (`valueGoesAwayWithFiber` catches it).
- Cancellation is propagated eagerly: `Scope::notify()` marks every scope of the non-shielded subtree, and a new scope
  inherits the mark from its parent unless it is a shield. `Scope::isCancelled()` stays a flag read; do not walk the tree.
  One `cancelled` flag per scope is enough because it never goes back; `cancel()` on a cancelled scope is a no-op.
  A wait subscribes its `ContinuationImpl` itself (`Scope::subscribe()`/`unsubscribe()`), without allocating closures.
- The deadline is resolved like the cancellation mark: a `Scope` stores the absolute deadline (`Scope::now()`, null if none),
  the minimum of its own and the parent's, and a shield drops the parent's. `remaining()` reads one field and the clock;
  `suspend()` does not touch the deadline: only the `timeout()` timer enforces it.
- `Internal\Scope::now()` is the only clock of the library (`Instant`, deadlines, the rate limiters): never call `hrtime()`
  or `microtime()` elsewhere. Within `VirtualTime\run()`, it reads `Internal\Scope::$virtualClock`, bound by `run()`:
  never find the clock through `EventLoop::getDriver()`, the driver may be decorated. The binding is on `Scope`, not on
  `Internal\VirtualClock`, so that an application that never calls `run()` never loads the class. The clock is not read in `suspend()`.
  The virtual clock starts at `Scope::now()` rounded up to a whole second, not at zero: a time captured before `run()`
  stays in the past.
- `Internal\VirtualTimeDriver` jumps the clock only when nothing has been given, enabled or run during two pumps in a row:
  the pump is a defer, and the defers of a tick run before the stream and signal callbacks found ready in it,
  so after one idle pump a stream written to inside the process may still be ready behind it.
  It keeps counters of pending (enabled, not firing) and referenced timers: no operation scans the timers.
  An outdated entry of its heap is dropped at the top, or by a rebuild once outdated entries outnumber pending timers.
  `VirtualTime\run()` throws `LogicException` in a fiber, and restores the outer clock only after the last tick is finished.
- A rate limiter keeps absolute times and sleeps once per call, without sleeping again after the wake-up:
  a wake-up a hair early (Revolt's timers are float seconds) must not loop on a delay too small to move the clock.
  It reads the clock after the wake-up only to account for a late one: a blocked loop must not let `$burst + 1` calls
  through. The sliding window stores the later of the planned and the actual start; the token bucket restarts its
  counting from the wake-up only if the bucket has been full for a whole token interval by then. A smaller lateness
  keeps the schedule: a real timer is late on every call, and restarting from each wake-up ran a `burst: 1` limiter
  a quarter below its rate.
- A duration passed in is `Time\Duration` only (`delay()`, `timeout()`, `timeoutOr()`, `StreamWatcher::delay()`,
  the window of the rate limiter, `VirtualTime\advance()`), and `remaining()` returns one. A point in time is `Instant`, not a
  `Duration` (as `thesis/time` decided) and not a float: the difference of two is a `Duration`.
  Its shape follows the `Instant` sketched in the future scope of the Duration RFC: `add(Duration)`, the difference
  is a signed `Duration`, no adding of two instants. A native one would not follow `VirtualTime\run()`, so ours stays
  even then; it may wrap the native one. A rate (`$perSecond`) is not a duration and stays a float.
- Inside, time is `int` nanoseconds: `Internal\Scope::now()` is `hrtime(true)` or the virtual clock, and so are
  the deadlines, `Instant`, the rate limiters and the virtual timers. Exact like `Duration` (the same representation,
  `ns()`/`toNs()` convert without a loss) and as cheap as a float. Revolt is the only float boundary: `$ns / 1e9`
  into `EventLoop::delay()`, rounded back in `VirtualTimeDriver`. An int overflow turns into a float and a
  `TypeError`, so every sum with a `Duration` or a rate from outside is guarded. A deadline, a virtual timer,
  the virtual clock and the times of the rate limiters saturate at `PHP_INT_MAX` (`Scope::after()`,
  `VirtualTimeDriver::enqueue()`, `VirtualClock::advance()`, the window, `tokenAt()`): a timeout of centuries is still
  a deadline, only one that never comes. `Scope::remaining()` and the sleep of a rate limiter are capped at
  `Scope::LONGEST_DURATION`, a little below `PHP_INT_MAX`, so `ns()` of them never throws.
  `Instant` is a value, so it does not saturate: `add()` and `since()` throw
  `Time\TimeException` beyond int, as `Duration` does beyond its range.
  Rejected: float seconds (they lose nanoseconds after about 100 days of uptime and do not convert to `Duration`
  exactly), `Duration` objects (about 500 ns an operation on the polyfill against 40, and an allocation each).
- `ScopedValue`s live outside the scope tree, in `Internal\Context`: an immutable array per fiber, copied by `with()`.
  A task's scope is a child of the group, not of the spawn site, so `GroupImpl::start()` captures the values
  in the spawning fiber and the task runs with them. A shield does not hide values. A task that inherits nothing
  pays for no binding; `suspend()` never reads values.
- In `{main}`, `GroupImpl::join()` runs the event loop and Revolt may throw out of it: an `UncaughtThrowable` fails
  the group and the join goes on; a loop that ran dry fails the group too, but if it runs dry while the body is already
  cancelled, the join gives up and rethrows. Never let such an exception skip the join or `$bodyScope->close()`.

## Decisions

- Rejected: `Once` (a lazy value computed once for concurrent callers, `Sync\Once` of the old thesis).
  Under structured concurrency it has no single meaning: the init runs either in the scope of the first caller,
  so cancelling that caller must make the next one retry, or in a group of an owner, so no caller's cancellation
  reaches it. Both are a few lines already: `synchronized($mutex, fn() => $this->value ??= $this->init())`
  (a cancelled caller releases the lock, the next one retries), or a `Task` of the owner's group kept in a property
  and awaited by everyone. A `Once` would have to pick one of them and invites the lazy connect of a client
  that `agents/clients.md` rejects. Shared DNS queries in thesis/net need the second meaning,
  dic singletons are dic's own.
  Revisit when two packages repeat the same pattern with the same meaning of cancellation.
- `Backoff` computes the delays and does not wait: the wait of each user ends differently (`StreamWatcher::delay()`
  that `close()` ends in thesis/net, a `timeoutOr()` that `Client::close()` ends in thesis/amqp).
  Rejected: a `retry(callable, Backoff)`, it would fix the wait and which failures are retried.
  The jitter takes between half and all of a delay ("equal jitter"), not from zero ("full jitter"):
  a reconnect right after a loss is a storm, and a floor keeps the attempts paced.

## Conventions

- Every test runs on virtual time (`VirtualTimePlugin` of thesis/testo-plugin in `testo.plugins.php`; no attribute is needed): delays are whole seconds
  (`delay(sec(1))`), a wait that only a cancellation ends is `never()` from `tests/helpers.php`, durations are asserted
  exactly (`Assert::same(secondsSince($start), 20.0)`, `Assert::equals(remaining(), sec(990))`), and busy code is `VirtualTime\advance()`, not a loop on the real clock.
  Keep a real `delay()` where its timer matters: a test of `delay()` itself, or a timer that keeps the loop alive.
  Real time is only for what virtual time cannot model.
- A limiter, a pacer or a clock is tested beyond one caller on time: with several callers sharing it, with a blocked loop
  (`VirtualTime\advance()` while a caller sleeps), with a wake-up late by less than its interval (what a real timer does
  on every call, invisible on virtual time otherwise), with the longest `Duration` or an extreme rate, and, if it keeps
  a callback in the loop across calls, after that callback is dropped with its driver (`VirtualTime\run()` drops
  what is left). None of these was tested, and each hid a defect.
- A stream or a signal on virtual time is tested with another timer pending (a `timeout()` around it):
  without one, the pump stops while the inner driver waits, and the jump past a ready stream is never seen.
- An outer cancellation is tested against everything the group may have cancelled itself before it:
  daemons, `Task::cancel()`, `cancelTasks()`, the losers of `race()` and `some()`.
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
