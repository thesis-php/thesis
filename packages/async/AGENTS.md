# thesis/async

An experimental structured concurrency library on an event loop of its own (`Internal\Loop` on `Io\Poll` of PHP 8.6).
The model, the API and the guarantees are described in `README.md` — read it first.

## Invariants that must not be broken

- Every wait goes through `suspend()` or parks an `Internal\ContinuationImpl` registered in the loop
  (`delay()`, `checkpoint()`, `StreamWatcher`). Nothing else calls `\Fiber::suspend()`. Those of the loop take
  the steps of `suspend()` in its order (the check of cancellation, the registration, the subscription, the park
  through `Tracer::park()` if traced, the unregistration in `finally`) without its closures, a fifth of such a wait
  (`delay()`, `checkpoint()`, `StreamWatcher::wait()`). A wait on a queue
  of the library (`Deferred`, `Channel`, the semaphores) still goes through `suspend()`: `ideas/loop.md`.
- The `$unregister` returned from `suspend()`'s `$register` is always called once the wait is over, whatever ended it.
  Only a `\Closure` is called: an arrow function that resumes at once returns what it has called, and a string
  may be callable (`"die"`).
- Cancellation is level-triggered: while a scope is cancelled, every `suspend()` in it throws `Cancelled`.
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
- `checkpoint()` must yield to the event loop through `Loop::defer()`, not only check a flag:
  CPU-bound code starves timers, so a flag set by a `timeout()` timer would never be seen.
  Not `Loop::queue()` either: microtasks run before timers.
  The flag check alone is the internal `Scope::throwIfCancelled()`.
- If the event source fires before the cancellation, `suspend()` returns the value instead of throwing.
- A signal never cancels a scope by itself: a shutdown is a group whose body returns after `awaitSignal()`.
  `trapSignals()` holds handlers of the loop (`Loop::onSignal()`, installed at once) while its callable runs, and needs
  ext-pcntl (`LogicException` without it, which is why the package only suggests it); `awaitSignal()` is
  a trap around one `receive()`, so a signal outside the wait gets the default action (a second Ctrl+C kills the
  process). `SignalTrap` is not a queue and not a `Channel`: like the OS, at most one pending signal per number, in the
  order they first came, so a repeated signal never pushes out another one. It hands a signal over like `Channel` hands
  a value: only to a receiver whose `resume()` returns `true`, otherwise to the next one or to pending.
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
  `allSettled()` never records `Cancelled` as an outcome: it cancels nothing, so a cancelled task means the caller is
  cancelled.
- Every other failure of a group is recorded by `GroupImpl::fail()` through `Internal\Suppressed`: a `WeakMap` from the
  rethrown error to the list of the others. The rethrown error is never changed (no wrapper, no `previous`); the same
  object is recorded at most once and never under itself; recording an error under one from its own tree drops the edge
  back, so the tree stays acyclic and everything stays reachable from the rethrown error. When a join gives up on a
  deadlock, the group's failure is recorded under the `Deadlock`, unless it is the same deadlock from an earlier round.
- `Channel` hands values over like `LocalSemaphore` hands permits: a value goes to a waiting receiver, or is taken
  from a waiting sender, only if that waiter's `resume()` returns `true`; otherwise it goes to the next one or to the
  buffer. So a `send()` that throws `Cancelled` has not delivered, and a `receive()` that throws `Cancelled` has not
  taken anything. Waiting receivers imply an empty buffer; waiting senders imply a full one (or a rendezvous).
- `each()` and `map()` run on `Internal\Workers`: at most `concurrency` tasks, each takes the next item under
  `synchronized()` (a generator may suspend, and another worker must not resume it meanwhile); an array cannot suspend,
  so it is taken without the lock. An item is pulled when it is taken, never ahead: `next()` before `valid()`, not after
  `current()`. Taking checks cancellation first (after getting the lock), so nothing is pulled after a failure.
  A new worker is started only when the item just taken leaves no worker free.
  The callable gets the value only (`$fn($value)`, so an internal function fits); only `map()` uses the key, for its
  result. `each()` keeps no results, so its memory does not grow with the items, and its keys need not be array keys.
- `StreamWatcher` is an object owned with the stream, not free functions like `awaitReadable($stream)`: the owner must
  be able to end every wait before `fclose()` (the kernel drops a closed descriptor, and nothing would wake its
  waits). `close()` resumes the waits with `false` and cancels their watchers; a wait after `close()` returns `false`
  without suspending or a cancellation check, like `receive()` of a closed channel. Its `delay()` is there for the
  owner's backoff (an accept loop), which a closed stream must end too; it follows `delay()`: `<= 0` is zero.
- `Pacer` is separate from `StreamWatcher`: fairness of a fast path has nothing to do with streams
  (a hot channel needs it too). It counts calls per tick: the count starts anew when `Loop::$tick` differs from
  the one it counted, so it registers nothing. Past the limit it calls `checkpoint()` *before* the operation,
  so a `Cancelled` from it means the operation did not happen. After that yield it counts the new tick, where other
  callers of a shared pacer may have counted already (a reset let N fibers pass N × `$perTick`). The numbers of ticks
  are unique among all the loops: a pacer shared by a nested loop (`stdout()` of thesis/io in `virtualTime()`) would
  otherwise count the calls of another loop's tick with the same number.
  Calls under the limit do not check cancellation: a fast path stays a counter increment.
- A parent scope holds its children strongly and a child holds its parent: every created `Internal\Scope` must be closed
  with `close()`, the tasks and daemons scopes of a group too, or they form a cycle that only the cycle collector frees.
- `Internal\Scope::current()` is on the hot path of every wait: keep it a single `WeakMap` lookup (see the note on
  `CURRENT_SCOPE`). Per-fiber state (`CURRENT_SCOPE`, `CONTEXT`, the locks of `synchronized()`) is a plain
  `WeakMap` keyed by `Internal\fiberKey()`, by the rules of `agents/fibers.md` (`valueGoesAwayWithFiber`).
- Cancellation is propagated eagerly: `Scope::notify()` marks every scope of the non-shielded subtree, and a new scope
  inherits the mark from its parent unless it is a shield. `Scope::$isCancelled` stays a flag read; do not walk the
  tree. One `cancelled` flag per scope is enough because it never goes back; `cancel()` on a cancelled scope is a no-op.
  A wait subscribes its `ContinuationImpl` itself (`Scope::subscribe()`/`unsubscribe()`), without allocating closures.
- The deadline is resolved like the cancellation mark: a `Scope` stores the absolute deadline (`Scope::now()`, null if
  none), the minimum of its own and the parent's, and a shield drops the parent's. `remaining()` reads one field and the
  clock; `suspend()` does not touch the deadline: only the `timeout()` timer enforces it.
- `Internal\Scope::now()` is the only clock of the library (`Instant`, deadlines, the rate limiters): never call
  `hrtime()` or `microtime()` elsewhere. Within `virtualTime()`, it reads `Internal\Scope::$virtualClock`, which
  `virtualTime()` binds to the clock of its loop. The binding is on `Scope`, not on `Internal\VirtualClock`,
  so that an application that never calls `virtualTime()` never loads the class. The clock is not read
  in `suspend()`. The virtual clock starts at `Scope::now()` rounded up to a whole second, not at zero:
  a time captured before `virtualTime()` stays in the past.
- `virtualTime()` runs a loop of its own on a `VirtualClock` and releases it at the end (`Loop::release()`):
  what is left in it is dropped. In a fiber (a task, a test of Testo) it is a nested loop that blocks the outer one
  until it ends; the outer clock is restored after its last tick.
- A rate limiter keeps absolute times and sleeps once per call: a timer of the loop never fires early,
  so a second sleep after the wake-up would never be needed.
  It reads the clock after the wake-up only to account for a late one: a blocked loop must not let `$burst + 1` calls
  through. The sliding window stores the later of the planned and the actual start; the token bucket restarts its
  counting from the wake-up only if the bucket has been full for a whole token interval by then. A smaller lateness
  keeps the schedule: a real timer is late on every call, and restarting from each wake-up ran a `burst: 1` limiter
  a quarter below its rate.
- A duration is `Time\Duration` (`php.md`), `remaining()` returns one. A point in time is `Instant`,
  not a `Duration` (as `thesis/time` decided) and not a float: the difference of two is a `Duration`.
  Its shape follows the `Instant` sketched in the future scope of the Duration RFC: `add(Duration)`, the difference
  is a signed `Duration`, no adding of two instants. A native one would not follow `virtualTime()`, so ours stays
  even then; it may wrap the native one. A rate (`$perSecond`) is not a duration and stays a float.
- Inside, time is `int` nanoseconds (`php.md`): `Internal\Scope::now()` is `hrtime(true)` or the virtual clock,
  and so are the deadlines, `Instant`, the rate limiters and the timers of the loop, with no float boundary:
  the timeout of `Io\Poll` is a `Duration`. An int overflow turns into a float and a
  `TypeError`, so every sum with a `Duration` or a rate from outside is guarded. A deadline, a timer,
  the virtual clock and the times of the rate limiters saturate at `PHP_INT_MAX` (`Scope::after()`,
  `Loop::delay()`, `VirtualClock::advance()`, the window, `tokenAt()`): a timeout of centuries is still
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
- In `{main}`, `group()` runs the event loop around itself (`Loop::get()->run()`), so its body and join run in the fiber
  of the loop, with the scope, the scoped values and the locks of `synchronized()` of the caller
  (`Internal\inheritingFiber()`): a `timeout()` or `with()` around it and a reentrant lock must hold as in a fiber.
  Where the loop itself runs (a callback of `onCancel()`, a watchdog) `group()` throws `LogicException` before
  anything starts: its join could not wait, and each round of it would fail the group again. The loop throws
  what escapes to it: such an error fails the group and the join goes on;
  a `Deadlock` fails the group too, but if it comes while the body is already cancelled, the join gives up and rethrows.
  Never let such an exception skip the join or `$bodyScope->close()`.
- Tracing (`Trace\observe()`) only observes and costs nothing without an observation: every hook is
  `Scope::$tracer?->...`, which evaluates no arguments while it is null, and `suspend()` reads `Scope::$tracer`
  once and hands the rest of the wait to `Internal\Tracer::park()`, so the untraced wait is the code it was;
  the waits of the loop do the same.
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

## The event loop

`Internal\Loop` schedules fibers on PHP 8.6's `Io\Poll`. `Loop::get()` returns the active run's loop
or the process loop. `virtualTime()` runs a separate loop.

- It runs in {main} or, nested, in the fiber of an outer loop (`$host`), and the code it runs only in fibers:
  a wait where it runs throws `LogicException`, `run()` refuses itself inside. A run ends when its fiber ends.
  Its own code between the fibers (the callbacks of `onCancel()`, watchdogs, signal handlers) runs
  in `Scope::runInRoot()`: the host is blocked in `run()`, and its scope and values are not theirs.
  `Pacer` does not yield there, nor outside a run: nothing else would run, and nothing could resume it.
  A loop that is dropped is released first (`release()`): a fiber of a closure bound to an object that holds its wait
  (a task of the user) is a cycle that PHP 8.6 never frees (`agents/fibers.md`), and its wait holds the loop,
  so `release()` drops the fibers, timers and streams and closes the descriptors: only the loop object may stay.
- A fiber may run another one inside and pass its suspensions through (Testo does it with a test): `park()` leaves
  its continuation in `Loop::$parked`, and the loop resumes the fiber that returned to it. Never pass a value
  through `\Fiber::suspend()`: see `agents/fibers.md`. A continuation left by a fiber that has returned to one
  that has ended is dropped: nobody resumes that wait, as with any fiber its owner has left.
- A tick wakes the waits deferred to it and the due timers, resumes the fibers that were ready when it began and
  drains the microtasks after each one: what they make ready waits for the next tick, so two fibers that ping-pong
  cannot starve the streams and the timers. A started task is a microtask: it runs after the current one suspends.
- A task runs in a fiber of the pool (`Loop::work()`): a fiber whose task has ended waits for the next one,
  up to `IDLE_FIBERS`, and ends once the pool is full (a suspended fiber that the loop drops is unwound,
  which costs more). A new fiber costs its stack in the kernel, which Tally does not count: a short task of `all()`
  took 8 µs and takes 2 µs in a fiber of the pool. The task and its end pass through statics, never through
  `\Fiber::suspend()` or a captured object, and `work()` is the only frame under the task: `Tracer` looks through
  `FRAMES` frames only. An idle fiber is not in `$fibers`: a `Deadlock` lists the fibers that wait.
- A wait allocates nothing in the loop: timers, streams and the deferred hold a `Waiter` (`ContinuationImpl`, `Scope`
  as the timer of a timeout), which puts its fiber to the ready queue. Only a signal handler is a closure.
- An error that escapes to the loop (a microtask, a wake, a fiber other than the `run()` fiber) is thrown into
  the wait of the fiber of `run()` (`$mainWait`), but only into one that has not fired: a value handed to it
  is never lost, the error waits for its next wait. One left when that fiber ends is thrown from `run()`.
- Nothing ready and no referenced timer, no stream wait and no signal handler while the fiber of `run()` waits:
  `Deadlock` (`@api`, an `\Error`) is thrown into its wait, with the place of every suspended fiber.
- Timers are a heap with lazy deletion: a cancel nulls the waiter, and the heap is rebuilt once outdated entries
  outnumber the pending ones (at least 64). A cancelled timer at the top leaves at once: the timer of a timeout
  is mostly the nearest one, and so a timeout costs no rebuilds (`TimeoutBench`). Expirations saturate
  at `PHP_INT_MAX`, the timeout of a wait is capped at `Scope::LONGEST_DURATION`. An unreferenced timer fires
  but keeps nothing from a deadlock (`Trace\watchdog()`).
- `Io\Poll` takes a stream once per context (`HandleAlreadyWatchedException`), so the readers and the writers
  of a stream share one `WatchedStream`, and its watcher is brought up to date lazily, right before a wait:
  a fiber that waits again in the tick it was woken costs no call to the kernel. Empty events are a `TypeError`,
  so a stream nobody waits for is removed. Level-triggered: bytes in the buffer of PHP count as readable.
- The `Watcher` holds its `WatchedStream` as data: the watcher of a stream nobody waits for, and every one
  in `release()`, is nulled after `remove()`, or the cycle keeps the descriptor open until the collector runs
  and a socket dropped by thesis/net is not closed for its peer. `release()` nulls the waiters of its timers too:
  a cancel after it (a watchdog, a fiber destroyed in its wait) finds them done.
- `fclose()` makes the watcher inactive and nothing wakes its waiters: the owner ends them before (`StreamWatcher`).
  An inactive watcher is dropped before the next wait, so a closed stream watched again is found closed.
  A stream that cannot be watched never blocks: its waiters are woken at once, the read or the write tells.
  Closed: `TypeError`; without a descriptor (`php://memory`): a warning and `InvalidHandleException`;
  not pollable (`/dev/null`, `php://temp`, `data://`): `FailedHandleAddException` with `ERROR_NOSUPPORT`.
  Any other code of it (no memory, the limit of watches) is thrown: woken at once, a socket would spin the loop.
- A signal cuts a wait of the kernel short whoever handles it, even nobody (`SIGCONT`): `FailedPollWaitException`
  with `ERROR_INTERRUPTED` is a wait with no events, any other code a failure.
- Signals: `pcntl_async_signals(true)` while a handler is there, the handler of `pcntl_signal()` only takes note and
  writes a byte to a self-pipe watched by the loop. A signal that comes after the last check of the engine, right
  before the wait, is only noted (no byte, no EINTR): while handlers are there, a wait lasts at most `SIGNAL_GAP`
  (100 ms), and `pcntl_signal_dispatch()` follows every wait. A signal handled there is an event of the wait
  as a stream is: the virtual clock does not jump past it. `onSignal()` reads the old handler before it opens
  the pipe: a number that is no signal throws `ValueError` with nothing left open. The pipe is drained whenever
  it is ready or a signal was caught. The loop restores the previous handler after the last registered handler leaves.
- With a seed (`virtualTime($fn, $seed)`) the loop shuffles the fibers ready at the start of a tick, the only
  place where the order of waking is decided; streams are not shuffled apart, their waiters are fibers that go
  through the ready queue. Without a seed, fibers run in wake order. Timers wake in deadline order.
  For equal deadlines, timers wake in creation order.
- On a virtual clock the loop never sleeps for a timer: where it would (nothing ready, `wait(0)` found nothing),
  it jumps the clock to the nearest timer, referenced or not.
- The timeout of `wait()` is rounded up to whole milliseconds: a timer never fires early, its precision is 1 ms.
  The other end of a socket pair closed makes a stream readable at once (hang-up).

## Decisions

- No `ext-io` in `require`: `Io\Poll` is a part of `ext/standard` of PHP 8.6 (`getExtensionName()` is `standard`),
  there is no such platform package, and Composer would fail on it. `php: ^8.6` already guarantees it.
- `Io\Poll::wait()` takes every ready event (no `maxEvents`): a cap would leave the rest for the next tick, which
  level-triggered watchers allow, but nothing asks for it.
- The gap of a signal before a wait is closed by time (`SIGNAL_GAP`), not by the kernel: PHP has no `signalfd`
  and no `pselect()`, and the handler of the engine writes nothing. Rejected: blocking the signals around the wait,
  then they could not cut it short at all. 10 wakes a second while a signal is handled cost nothing.
- The event loop is our own, never a third-party one under it or beside it: a foreign loop decides the order
  of the callbacks, has a suspension and fibers of its own and float seconds, while ours lets virtual time
  jump exactly where it would block. Rejected for now: a bridge that runs the code of another loop on ours.
  PHP 8.6 only, no fallback to `stream_select()`.
- The clock of the loop is a nullable `VirtualClock`, not an interface: `hrtime(true)` needs no object,
  and the loop must know a virtual one to jump it. Timers are a heap: a hierarchical wheel only if a benchmark
  asks for it.

- Tracing is one stream of event objects to a `Thesis\Dispatcher`, not a method per event: a new event is a new
  class, which breaks no listener at runtime, and the dispatcher tells which events nobody gets, so the tracer
  skips the backtraces of a wait for a listener of cancellations alone. Rejected: an `Observer` interface of our own,
  it routed by `instanceof` in every observer and could not tell the tracer what to skip.
  The events are a closed set (`@phpstan-sealed`): only the library creates them, and a tool that handles each one
  learns of a new one from PHPStan. Rejected: a list of the classes in a constant, a second list next to the sealed.
  Rejected: hooks on `Scope::__construct()` (the kind of a scope is known only
  where it is created), events from inside `notify()` (user code in the middle of the walk), a null check per event
  in `suspend()` (the traced wait is a separate method instead), a full backtrace per wait (`caller()` of
  thesis/source-location takes the first frame outside this library, and names the call that waits).
- `Once::await()` runs the callback in the first caller's scope. All callers share its result or ordinary failure.
  Only `Cancelled` escaping the callback can reopen a `Once` constructed with `retryOnCancel: true`.
  A waiter cancelled in its own scope leaves the attempt alone. Work owned by a group (such as a shared DNS query)
  belongs in a task of that group. Rejected: starting the callback in a background task from the constructor.
- `Backoff` computes the delays and does not wait: the wait of each user ends differently (`StreamWatcher::delay()`
  that `close()` ends in thesis/net, a `timeoutOr()` that `Client::close()` ends in thesis/amqp).
  Rejected: a `retry(callable, Backoff)`, it would fix the wait and which failures are retried.
  The jitter takes between half and all of a delay ("equal jitter"), not from zero ("full jitter"):
  a reconnect right after a loss is a storm, and a floor keeps the attempts paced.
## Conventions

- Every test runs on virtual time (`VirtualTimePlugin` of thesis/testo-plugin in `testoPlugins` of the manifest; no
  attribute is needed): delays are whole seconds (`delay(sec(1))`), a wait that only a cancellation ends is `never()`
  from `tests/helpers.php`, durations are asserted exactly (`Assert::same(secondsSince($start), 20.0)`,
  `Assert::equals(remaining(), sec(990))`), and busy code is `burnCpu()`, not a loop on the real clock. Keep a real
  `delay()` where its timer matters: a test of `delay()` itself, or a timer that keeps the loop alive. Real time is only
  for what virtual time cannot model.
- A limiter, a pacer or a clock is tested beyond one caller on time: with several callers sharing it, with a blocked
  loop (`burnCpu()` while a caller sleeps), with a wake-up late by less than its interval (what a real timer
  does on every call, invisible on virtual time otherwise), with the longest `Duration` or an extreme rate, and, if it
  keeps a callback in the loop across calls, after that callback is dropped with its loop (`virtualTime()` drops
  what is left), and across a nested loop. None of these was tested, and each hid a defect.
- A stream or a signal on virtual time is tested with another timer pending (a `timeout()` around it):
  without one, the clock has nowhere to jump, and a jump past a ready stream is never seen.
- A callback of the loop itself (a timer of a library, not a fiber) is `later()` of `tests/helpers.php`.
- A lock held for one method is `synchronized($mutex, $this->method(...))`, not `acquire()` with a release
  in `finally`: `Lock` releases itself in its destructor too, so mutation testing shows the `finally`
  as code no test tells apart (the rate limiters).
- An outer cancellation is tested against everything the group may have cancelled itself before it:
  daemons, `Task::cancel()`, `cancelTasks()`, the losers of `race()` and `some()`.
- A behaviour that depends on combinations is also checked on generated programs: `RandomizedVirtualTimeTest`
  (random pipes, relays, raw timers and daemons: when the clock may jump) and `CancelledFirstTest` (every
  combination against a model of who cancelled first). A new way to wait on virtual time or to cancel a task
  is added to them. A combination that cannot behave differently from another one is left out (a cleanup
  of a task that ends before anything cancels it): `CancelledFirstTest` once took a third of the suite.
  Rejected: running the cases concurrently in one `virtualTime()`, it was five times slower (thousands of live fibers).
  Rejected: a random stress of groups, channels and semaphores with general checks.
  It never found a defect, not even the ones a review found by reading.
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
