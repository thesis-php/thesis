# The event loop

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
- The newest timer stays outside the heap until another timer is registered or the loop needs the heap.
  A synchronous operation can cancel its deadline without inserting or extracting a heap entry.
  On PHP 8.6, deferring the heap entry (`f39fec8`) cut instructions by 5.0% in `TimeoutBench`
  and added 1.7% in `DelayBench`. Run both when changing the heap: one cancels timers, the other fires them.
  A timer registered by a waking timer can still fire in the same tick. A cancel nulls the waiter,
  and the heap is rebuilt once outdated entries outnumber the pending ones (at least 64).
  A cancelled timer at the top leaves at once. Expirations saturate
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
