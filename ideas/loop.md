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
  A wait in `{main}` throws `LogicException` telling to wrap the code in `group()` (stage 2: a wait outside
  a fiber, not in the root scope: a fiber of the user in the root scope, as the tests start, can wait).

## Design

- Built in stage 2, described in "The event loop" of `packages/async/AGENTS.md`: the core, its tick, timers,
  streams, signals, deadlocks and the jump of a virtual clock. The clock is a nullable `VirtualClock`
  rather than an interface: `hrtime(true)` needs no object, and the loop must know a virtual one to jump it.
- `checkpoint()` puts the fiber to the next tick. The tick number is a property of the loop, so `Pacer`
  compares two numbers instead of resetting itself by a `defer`.
- Timers: a hierarchical wheel only if a benchmark asks for it.
- The heartbeat of the detector of endless runs (`endless-runs.md`, open question 1) is the start of the tick,
  a field: no timer wakes an idle process.
- Virtual time is a clock, not a decorator of a driver: it jumps to the nearest timer only where the loop
  is about to block (ready and microtasks empty, `wait(0)` found nothing). The pump of `VirtualTimeDriver`,
  its two idle pumps and its trap with code that enters the loop past the driver go away.

## Stages

Each stage ends with a green `make check` and a commit. Before each stage
read `packages/async/AGENTS.md`, `agents/fibers.md`, `agents/tests.md`, and `agents/benchmarks.md` for 1 and 5.

1. Done: `ChannelBench`, `TimeoutBench`, `DelayBench`, `StreamWatcherBench` and `CheckpointBench` of async
   have their numbers of Revolt (`UvDriver`, the default of the image, counted on aarch64) in `#[Expected]`.
2. Done: `Internal\Loop` and `tests/Internal/LoopTest.php`. `ContinuationImpl` parks on it while it runs.
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
2. Answered in stage 2: `Thesis\Async\Deadlock`, an `@api` `\Error` (as Revolt's was): a group must tell it
   from other failures, and a `LogicException` is an `\Exception` that a `catch (\Exception)` of user code takes.
3. A throw from an `onCancel()` callback: today it goes to the event loop. Out of the `group()` that runs
   the scheduler in `{main}`, as a failure of that group?
4. Answered in stage 2: unbounded, see "Decisions" of `packages/async/AGENTS.md`.
