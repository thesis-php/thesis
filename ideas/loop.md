# The event loop: next stages

`Internal\Loop` of thesis/async on `Io\Poll` of PHP 8.6 is built and described in "The event loop"
of `packages/async/AGENTS.md`. What it can give that a loop of a third party could not, a plan, not a decision:
each stage moves what it settles to `packages/async/AGENTS.md` (or a topic) and deletes it from here.
Before a stage read `packages/async/AGENTS.md`, `agents/fibers.md`, `agents/tests.md` and `agents/benchmarks.md`.
Tally counts no time in the kernel: a change that saves syscalls (a fiber stack, a watcher) is also timed
on the wall clock.

## Stages

1. **The numbers of the benchmarks.** The async `#[Expected]` values still reflect the previous loop.
   Instructions and cycles are below them in all five, memory is about 20 KiB above in `CheckpointBench`
   and `TimeoutBench`: the fiber of `Loop::run()` for a `group()` in `{main}`, which could come from the pool
   of the tasks too. Then `make bench-accept P=async`.
2. **Waits on the queues without closures.** The waits of the loop take the steps of `suspend()` without its
   closures (`delay()`, `checkpoint()`, `StreamWatcher::wait()`): `CheckpointBench` −9 %,
   `StreamWatcherBench` −14 % of instructions, a `delay()` on the wall clock 2.9 → 2.2 µs. `Deferred`, `Channel`
   and the semaphores still pay for a closure of `$register`, one of `$unregister` and their captures per wait:
   the same for a queue of continuations would show in `ChannelBench`.
3. **The probe of the steps.** The loop knows a run of code exactly: a call of `runFiber()`, a microtask,
   a callback of a timer or a signal. `BlockingDetector` of thesis/async-trace rebuilds it from the events
   of the scopes, with the traps of its `AGENTS.md` (the join waits without `suspend()`, the waits of the root
   scope have no events, a run whose end is lost is dropped) and a backtrace per wait. A probe of the loop
   (the start and the end of a step and its fiber, two `hrtime()` a step while an observation runs) sees
   the root scope and the callbacks of the loop too, takes the stack by `ReflectionFiber` only for a run
   past the threshold, and makes the detector cheap enough for production. Its start is the heartbeat
   of `endless-runs.md`. The fibers of the loop are also the dump of the live tasks of `trace.md`:
   `Loop::describeDeadlock()` walks them already. A tracer maps a fiber to its task at `TaskStarted`.
4. **One clock.** `Scope::$virtualClock` and `Loop::$clock` are the same clock bound twice: `Scope::now()`
   could read the clock of the current loop. Check that `burnCpu()` and `Instant::now()` between the runs
   of a `virtualTime()` still read it.
