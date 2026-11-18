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
  `group()` called outside a run of the loop (in `{main}`, or in a fiber of another scheduler such as Testo)
  runs its body in a fiber of its own and the scheduler until the group ends; a wait outside a run, or where
  the loop runs, throws `LogicException` telling to wrap the code in `group()` (stage 3). A fiber of the loop
  in the root scope (a test on `VirtualTimePlugin` or `EventLoopPlugin`) can wait.

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
3. Done: async, async-trace, io and net run on `Internal\Loop`; Revolt and amphp are gone from the manifests.
   Done with it, since the tests needed them: the virtual time of stage 4 without the shuffle
   (`virtualTime()` runs a loop of its own and releases it), and of stage 5 everything but the cleanup
   of `Makefile`, `tally.php`, `check.yaml` and the root `AGENTS.md`.
4. **The shuffle.** The shuffle by a seed of the ready snapshot and of the ready streams; testo-plugin prints
   the seed of a failed test and takes one from the environment to replay it. `RandomizedVirtualTimeTest`
   and the conventions of virtual time in `packages/async/AGENTS.md` follow.
5. **The rest of the cleanup.** `REVOLT_DRIVER`/`DRIVER` from `Makefile`, `tally.php` and `check.yaml`,
   `revolt` from the keywords of `monorepo.php`; `make sync`. The root `AGENTS.md` (the user agreed): the first
   line and the drivers in "Commands". `fibers.md`: the rule on the order of callbacks says the shuffle.
   Run `make bench` against stage 1 again.
5a. **A pool of fibers** (asked by the user). A new fiber costs about 5.3 µs (its stack), a task resumed
   in a fiber that is alive already 0.14 µs: a fiber whose task has ended takes the next one instead of ending.
   The state per fiber (the scope, `ScopedValue`, the locks of `synchronized()`) is reset between tasks,
   `bindingInTaskDoesNotLeakIntoNextTaskInSameFiber` of `ScopedValueTest` comes back, a `SpawnBench`
   of short tasks shows the gain.
6. **Docs and releases.** The README of async ("How it works", virtual time, signals, no amphp),
   the READMEs of io and net where they name drivers, the changelogs of every changed package
   by `changelogs.md`, the versions by `versions.md`.

## Questions for the stages

1. Answered in stage 3: `Trace\watchdog(Duration, callable): \Closure`, an unreferenced timer that calls
   a function outside the fibers and returns its cancel.
2. Answered in stage 2: `Thesis\Async\Deadlock`, an `@api` `\Error` (as Revolt's was): a group must tell it
   from other failures, and a `LogicException` is an `\Exception` that a `catch (\Exception)` of user code takes.
3. Answered in stage 3: it is thrown into the wait of the fiber of the loop, so out of the `group()`
   that runs the loop in `{main}`, as a failure of that group.
4. Answered in stage 2: unbounded, see "Decisions" of `packages/async/AGENTS.md`.
