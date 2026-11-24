# Trace: ideas

This draft expands "Observability of the task tree" in `roadmap.md`. It proposes tools that consume
`Trace\observe()` events from thesis/async and describes their users and implementation order.

## The base: two things every tool builds on

- **A serializable record of an event.** The events hold live objects (`$value`, `$error`, `$task`), and a listener
  must not keep them. The first layer is a listener that turns an event into a flat record right away: scalars,
  values and errors formatted by thesis/formatter, a location and a caller as strings. Written as NDJSON,
  one line per event, with a version of the schema. Every other tool reads it: from a file, a socket, a test,
  JavaScript in a browser. Without it each tool would reinvent reading the events.
- **A model of the state of the tree.** A listener that keeps what is alive now: the scopes and their parents,
  who waits where and since when, how long the current run of a scope lasts. The dump, the watchdogs, the live view
  and the invariant checks are built on it. `tests/TraceChecker.php` of thesis/async is its beginning.

## Three ways to run

| Mode | How | For |
|---|---|---|
| Record, then report | `observe()` writes NDJSON to a file, a report is built from it | the course, a bug, CI |
| Live | the events go to a Unix socket or a file, another process shows them like `top` | a long-running process |
| Snapshot on demand | SIGQUIT or SIGUSR1 through `SignalTrap` dumps the live tasks | a deadlock, production |

## Why a listener must not suspend the fiber

`observe()` forbids a listener to throw, suspend or cancel, though `Dispatcher` itself allows a listener to suspend.

1. **An event comes from the middle of an operation.** A listener runs synchronously inside the hooks.
   `Suspended` is emitted in `Internal\Tracer::park()` when the wait is already registered (the continuation
   is subscribed to the cancellation of the scope, the timer or the watcher that wakes it is set) but `park()`
   has not been called yet. A listener that waits there (a write to a socket through thesis/io):
   - delays the wait it reports: if that timer fires meanwhile, its continuation is not parked yet, so it only
     takes the value, and `park()` returns it once the listener is done. Nothing is lost, but the wait is not
     the one the trace says it is;
   - emits a `Suspended` of its own write, gets it and writes again: endless recursion.

   `ScopeCancelled` comes right after `Scope::notify()` has marked the subtree: while the listener waits,
   other fibers see the tree half-way.
2. **Not every event comes from a fiber that can wait.** A cancellation by a deadline comes from a timer
   of the loop, where a wait throws `LogicException`, other events come from `{main}` outside a run.
   A listener that works in some places and fails in others is worse than a rule.
3. **The observation changes what it observes.** Even a harmless wait would reorder the fibers and move
   the virtual clock: the trace would describe another program, and the determinism on virtual time,
   which the snapshot tests rely on, would be gone.

So the live mode writes synchronously to a buffer in memory, and a `watchdog()` that arms itself again flushes it
to a non-blocking socket. The callback is outside the tree: it does not appear in the trace and does not touch
the fibers of the tasks. A reader that falls behind fills the buffer, and the events are dropped with a counter
of the losses: the program never slows down for its observer.

## Tools by purpose

### Tests

- **`testo-plugin`**: a failed test gets its trace in the report. Invariants are checked on the way:
  every created scope has ended, no task has outlived its group. A scope still open at the end of a test
  is a leaked task.

### Debugging

- **A dump of the live tasks, as a goroutine dump.** It may need no events at all: walk the live tree from the root
  and take where each waits from `ReflectionFiber::getTrace()` at the moment of the dump. A dump that costs nothing
  until it is asked for, fit for production. The price: the names and the locations live only in the tracer
  (a decision, so that a task without an observation does not pay memory for them), so without an observation
  there are none. A choice to make explicitly: walk the tree, the events, or both. Through `SignalTrap` the dump
  never shows a run that never ends: the signal waits for the loop that run holds, see `endless-runs.md`.
- **A chain of causes** in `CancellationTracker`: it stops at the first cause, a task failed
  by the `TaskCancelled` of another one is not followed.

### The course and understanding concurrency

- **An export to the Chrome Trace Event format (Perfetto).** The best value for its cost: no viewer to write.
  A track per scope, the waits and the runs as slices, a cancellation as an instant event with a flow arrow
  from `by` to its target. On virtual time the picture is the same on every run and can go into the materials.
- **Static text pictures** for slides and READMEs: an ASCII tree of the scopes with their outcomes,
  a Mermaid gantt or sequence diagram.
- **An interactive HTML player**: one self-contained file that steps through the events, the tree growing
  and a cancellation spreading over it. The most expensive one and the clearest for the course,
  worth it if Perfetto is not enough.
- **Exercises of the course checked by the trace**: "the program must cancel X by its deadline, not by a failure",
  a check in the style of `testo-plugin`.

### Production, after the HTTP server

- **Metrics**: the number of live tasks, a histogram of the waits, the cancellations by cause. The lifecycle events
  are enough, without `Suspended` and `Resumed`: those cost about 4 µs a wait for the backtrace.
- **OpenTelemetry**: a span per task is the wrong granularity. Tasks are rather events inside the spans
  of the user, with the context carried by `ScopedValue`. A topic of its own.
- **`/debug/tasks`**, as pprof: the same dump over HTTP.

## What the events lack

1. **Who waits for whom.** `Suspended` knows only the caller. A deadlock on `Lock` or `Channel` (in `roadmap.md`)
   needs the object of the wait and its holder: a wait-for graph.
2. **An observation that begins mid-run** sees scopes whose creation it has not seen. A live view attached
   to a running process needs an initial snapshot: a walk of the tree at the moment of attaching.
3. **The callbacks of the loop itself** (`onCancel()`, `watchdog()`, the handlers of signals) are outside the tree
   and invisible. That is fine, but it is said in the docs.

## Order

Done: the NDJSON record (`NdjsonWriter`), the text format (`TextLog`), `CancellationTracker`, `BlockingDetector`,
`LongWaitDetector`, each with the part of the model of the state it needs. Next:

1. The export to Perfetto: the course and debugging for a small price.
2. The model of the state as a public API, out of what the tools have needed.
3. The dump by a signal, once the choice between the walk of the tree and the events is made.
4. `testo-plugin`: the trace of a failed test, the invariants.
5. The live `top` over a socket, with a `bin/` of thesis/async-trace.
6. The HTML player, the metrics, OpenTelemetry.

## To discuss next

- Whether thesis/async-trace stays one package or becomes a core with exporters.
