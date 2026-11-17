# An event loop of our own: plan

thesis/async moves from Revolt to a scheduler of its own on `Io\Poll` of PHP 8.6. A plan, not a decision yet:
each stage moves what it settles to `packages/async/AGENTS.md` (or a topic) and deletes it from here.
The file goes away with the last stage.

## Decided

- One loop, no Revolt under it or beside it: async, io, net, async-trace and testo-plugin drop
  `revolt/event-loop`. The drivers `StreamSelect`, `Uv`, `Ev`, `Event` and their CI matrix go away.
- No bridge to Revolt for now: we build it for ourselves first. So amphp code does not run in the process,
  and `ampCancellable()` goes away with `amphp/amp` of the manifest; it comes back with the bridge.
  The bridge, later: a `Revolt\EventLoop\Driver` on our loop, checked by the `DriverTest` of Revolt.
- PHP 8.6 only, no fallback to `stream_select()`: the monorepo already requires 8.6.
- The loop lives in thesis/async as `Internal\Loop`, until the core has a second user.
- The order of what becomes ready in one tick: in production as the kernel gives it (free), on virtual time
  shuffled by a seed that a failure prints. It makes the rule of `fibers.md` checked on every run, instead
  of the matrix of drivers.
- Open question 5 of `roadmap.md` is answered: the scheduler runs only in `{main}`, user code only in fibers.
  `group()` called in `{main}` runs its body in a fiber of its own and the scheduler until the group ends;
  `suspend()` in the root scope throws `LogicException` telling to wrap the code in `group()`.

## Design

- The unit of scheduling is a fiber. The core knows five things: ready fibers, timers, streams, signals and
  microtasks (`onCancel()`). No `repeat`, no `enable()`/`disable()`, no string ids, no global error handler.
- A wait allocates no closure: `ContinuationImpl` implements an internal `Waiter`, timers, streams and signals
  hold it and put it to the ready queue. A resume is `$fiber->resume($value)` from the scheduler,
  no `Suspension`.
- Time is `int` nanoseconds throughout, the clock is an interface (`hrtime(true)` or virtual). A `Duration`
  is made only for `Context::wait()`.
- A tick: read the clock; move the expired timers to ready; resume a snapshot of ready, draining microtasks
  after each fiber (what the tick scheduled waits for the next one: two ping-pong tasks must not starve I/O
  and timers); `wait()` for 0 if ready is not empty, else until the nearest timer, else without a timeout;
  move the ready streams and signals to ready. Nothing referenced and nothing ready: the run ends, or it is
  a deadlock if fibers are still suspended.
- `checkpoint()` puts the fiber to the next tick. The tick number is a property of the loop, so `Pacer`
  compares two numbers instead of resetting itself by a `defer`.
- Timers: a heap with lazy deletion and a rebuild once outdated entries outnumber pending ones, as
  `VirtualTimeDriver` has now. Almost every timer of `timeout()` is cancelled, so a cancel must be cheap.
  A hierarchical wheel only if a benchmark asks for it.
- One watcher per stream (see the facts below), with the readers and the writers of the stream behind it.
- Signals: `pcntl_async_signals(true)`, the handler sets a flag and writes a byte to a self-pipe watched
  by the loop (a signal just before `wait()` is not lost). The flag tells EINTR from a failure of `wait()`.
  The handler of a signal is restored when its last trap ends.
- A deadlock is an error that lists where each suspended task waits, instead of "Event loop terminated".
- The heartbeat of the detector of endless runs (`endless-runs.md`, open question 1) is the start of the tick,
  a field: no timer wakes an idle process.
- Virtual time is a clock, not a decorator of a driver: it jumps to the nearest timer only where the loop
  is about to block (ready and microtasks empty, `wait(0)` found nothing). The pump of `VirtualTimeDriver`,
  its two idle pumps and its trap with code that enters the loop past the driver go away.

## Facts of `Io\Poll` (8.6.0RC7, Epoll, Linux image)

- A stream is added as `new StreamPollHandle($stream)`, once per `Context`: a second `add()` throws
  `HandleAlreadyWatchedException`, so reading and writing of one stream share a watcher (`modifyEvents()`).
- Level-triggered by default (`OneShot` and `EdgeTriggered` are events to ask for). Bytes in the read buffer
  of PHP count as readable: after `fgets()` took one line of two, `wait()` reports the stream at once.
- `fclose()` makes the watcher inactive and `wait()` goes on, but nothing wakes the waiters of the stream:
  its owner still ends them (`StreamWatcher::close()`).
- A stream without a descriptor (`php://memory`) makes `add()` warn and throw `InvalidHandleException`,
  though `StreamPollHandle::isValid()` is true. `SharedStream` of thesis/io meets such streams.
- A signal during `wait()` throws `FailedPollWaitException` (code 7, nothing about EINTR) after the handler
  of `pcntl_async_signals()` has run. Worth a report upstream before 8.6.0.
- The timeout is rounded up to whole milliseconds: 0.5 ms waits about 1.2, 1.5 ms about 2.1, never less.
  A timer never fires early, but its precision is a millisecond.
- The other end of a socket pair closed makes the stream readable at once (hang-up): a probe keeps both ends.

## Stages

Each stage ends with a green `make check` and a commit. Before each stage
read `packages/async/AGENTS.md`, `agents/fibers.md`, `agents/tests.md`, and `agents/benchmarks.md` for 1 and 5.

1. Done: `ChannelBench`, `TimeoutBench`, `DelayBench`, `StreamWatcherBench` and `CheckpointBench` of async
   have their numbers of Revolt (`UvDriver`, the default of the image, counted on aarch64) in `#[Expected]`.
2. **The core beside Revolt.** `Internal\Loop` with the clock, the ready queue, microtasks, timers, streams
   and signals, tested on its own (`tests/Internal/LoopTest.php`): order within a tick, a cancelled timer,
   a timer far away (saturation at `PHP_INT_MAX`), EINTR, a closed stream, a stream without a descriptor,
   a deadlock, no fiber kept after a run (`fibers.md`: a loop that has run goes away). Nothing uses it yet.
3. **async on the core.** The places that touch Revolt now: `ContinuationImpl::park()`, `delay()` and
   `checkpoint()` in `api.php`, the timer of `Expired`, `Scope::onCancel()` and its throw to the loop,
   `GroupImpl::start()` and `join()` (no `UncaughtThrowable`, `{main}` as decided), `SignalTrapImpl`,
   `StreamWatcher`, `Pacer`, the Revolt frames skipped by `Tracer`, the PHPDoc of `Continuation`. Remove
   `ampCancellable()` with its test, README section and manifest entries. Every test of async passes
   without being weakened; a test of what only Revolt did (a foreign callback, a dropped driver) is deleted
   with the reason in the commit; the 15 tests that call `EventLoop::` use the API of async.
   The benchmarks of stage 1 are accepted again only if no number is worse, or the user agrees.
4. **Virtual time on the core.** `virtualTime()` binds a virtual clock to a loop of its own, the jump
   as designed, `VirtualTimeDriver`, `VirtualTimer` and `releaseFibers()` deleted (check that the leak
   of `fibers.md` does not come back: the loop must not keep a suspended fiber of a closure bound to it).
   The shuffle by a seed of the ready snapshot and of the ready streams; testo-plugin prints the seed
   of a failed test and takes one from the environment to replay it. `RandomizedVirtualTimeTest`
   and the conventions of virtual time in `packages/async/AGENTS.md` follow.
5. **The other packages.** io: the `UvDriver` branch of `SharedStream` goes away. async-trace:
   `LongWaitDetector` needs an unreferenced timer, so async gets an `@api` way to it (see the questions);
   its tests stop reading `EventLoop::getIdentifiers()`. net: `EventLoop::defer()` of `SocketTest`
   and `ServerTest` become tasks. Drop `revolt/event-loop` from the manifests, `revolt` from the keywords
   of `monorepo.php` and of async, `REVOLT_DRIVER`/`DRIVER` from `Makefile`, `tally.php` and `check.yaml`;
   `make sync`. The root `AGENTS.md` (the user agreed): the first line and the drivers in "Commands".
   `fibers.md`: the rule on the order of callbacks says the shuffle; the rule on php-src#25540 goes
   if stage 4 removed its cause. Run `make bench` against stage 1 again.
6. **Docs and releases.** `packages/async/AGENTS.md` describes the loop (invariants, decisions, the rules
   of `VirtualTimeDriver` rewritten), the README of async ("How it works", virtual time, no amphp),
   the changelogs of every changed package by `changelogs.md`, the versions by `versions.md`.

## Questions for the stages

1. The `@api` way to an unreferenced timer for `LongWaitDetector` (stage 5): a function of async, or
   the detector becomes a daemon of a group the user passes.
2. What a deadlock throws: a new `@api` error of async, or `LogicException` with the list.
3. A throw from an `onCancel()` callback: today it goes to the event loop. Out of the `group()` that runs
   the scheduler in `{main}`, as a failure of that group?
4. `maxEvents` of `wait()`: unbounded, or a cap with the rest left for the next tick (level-triggered,
   so nothing is lost).
