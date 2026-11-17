# Trace: ideas

The tools around `Trace\observe()` of thesis/async: what to build on its events, for whom and in what order.
A draft, not a decision. It expands "Observability of the task tree" of `roadmap.md`.

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
| Snapshot on demand | SIGQUIT or SIGUSR1 through `SignalTrap` dumps the live tasks | a hung process, production |

## Why a listener must not suspend the fiber

`observe()` forbids a listener to throw, suspend or cancel, though `Dispatcher` itself allows a listener to suspend.

1. **An event comes from the middle of an operation.** A listener runs synchronously inside the hooks.
   `Suspended` is emitted in `Internal\Tracer::park()` when the wait is already registered (the continuation
   is subscribed to the cancellation of the scope, the timer or the watcher that wakes it is set) but `park()`
   has not been called yet. A listener that waits there (a write to a socket through thesis/io):
   - may be woken by that very timer: the resume goes to the wait of the listener, not to the original one,
     if both go through the one suspension of the fiber, as in Revolt (to check). A foreign value or a lost wake-up,
     that is a hang;
   - emits a `Suspended` of its own write, gets it and writes again: endless recursion.

   `ScopeCancelled` comes right after `Scope::notify()` has marked the subtree: while the listener waits,
   other fibers see the tree half-way.
2. **Not every event comes from a fiber that can wait.** A cancellation by a deadline comes from a timer callback,
   where Revolt does not allow a suspension (to check), other events come from `{main}` outside `run()`.
   A listener that works in some places and fails in others is worse than a rule.
3. **The observation changes what it observes.** Even a harmless wait would reorder the fibers and move
   the virtual clock: the trace would describe another program, and the determinism on virtual time,
   which the snapshot tests rely on, would be gone.

So the live mode writes synchronously to a buffer in memory, and a raw `EventLoop::repeat()` callback flushes it
to a non-blocking socket. The callback is outside the tree: it does not appear in the trace and does not touch
the fibers of the tasks. A reader that falls behind fills the buffer, and the events are dropped with a counter
of the losses: the program never slows down for its observer.

## Tools by purpose

### Tests

- **The text format of lines** (`0 #2 suspended in Thesis\Async\delay`): done, `TextLog` of thesis/async-trace.
- **`testo-plugin`**: a failed test gets its trace in the report. Invariants are checked on the way:
  every created scope has ended, no task has outlived its group. A scope still open at the end of a test
  is a leaked task.

### Debugging

- **A dump of the live tasks, as a goroutine dump.** It may need no events at all: walk the live tree from the root
  and take where each waits from `ReflectionFiber::getTrace()` at the moment of the dump. A dump that costs nothing
  until it is asked for, fit for production. The price: the names and the locations live only in the tracer
  (a decision, so that a task without an observation does not pay memory for them), so without an observation
  there are none. A choice to make explicitly: walk the tree, the events, or both.
- **A detector of blocking code.** From `Resumed` to the next `Suspended` or `Ended` of the same scope the fiber runs
  without giving up control. A run longer than a threshold is blocking code in a task, the most common bug
  of async PHP, and the events give it almost for free.
- **A watchdog of long waits**: a task in `Suspended` longer than a threshold, reported with its caller.
- **Who has cancelled me**: follow `ScopeCancelled::$by` and `$cause` to the first cause, such as "the deadline
  of `timeout()` at file:line". `Cancelled` itself carries nothing.

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

### Production, after `http-server`

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
3. **Raw `EventLoop` callbacks** are outside the tree and invisible. That is fine, but it is said in the docs.

## Order

1. The model of the state: the base, `tests/TraceChecker.php` of thesis/async is its beginning.
   Done: the NDJSON record (`NdjsonWriter` of thesis/async-trace) and the text format (`TextLog`).
2. The export to Perfetto: the course and debugging for a small price.
3. The detector of blocking code, the watchdog of long waits, who has cancelled me.
4. The dump by a signal, once the choice between the walk of the tree and the events is made.
5. `testo-plugin`: the trace of a failed test, the invariants.
6. The live `top` over a socket.
7. The HTML player, the metrics, OpenTelemetry.

## Where it lives

thesis/async keeps only the events, the tools go to thesis/async-trace, with a `bin/` for the reports and `top` later.

## To discuss next

- Whether thesis/async-trace stays one package or becomes a core with exporters.
