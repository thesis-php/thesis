# thesis/async-trace

The tools around `Trace\observe()` of thesis/async. thesis/async keeps only the events, everything built on them
lives here. The plan of the tools is `docs/trace-ideas.md` of the monorepo. The format is described in `README.md`.

This file is for agents changing this package. It is not loaded while an agent works in another package,
so how to *use* this package belongs in `README.md` or in a skill under `skills/<skill-name>/SKILL.md`.

## Invariants that must not be broken

- A listener only observes (`observe()` of thesis/async): `NdjsonWriter` and `TextLog` never throw (a failed write
  is counted in `$lost`), never suspend (a synchronous `fwrite()`, no thesis/io), and keep nothing of an event but
  the `Instant` of the first one: a value, an error and a task are formatted inside the method of the event.
  A tool (`WhoCancelled`) keeps only strings, numbers and `Instant`s, and only of live scopes: a process that
  lives long must not grow with it.
- The NDJSON is the base every other tool reads, and `README.md` is its specification: a change of the format
  changes the table there in the same commit. A field added or an event added keeps `VERSION`, a field removed
  or one whose meaning changes bumps it.
- `at` and `duration` are integer nanoseconds, like the time inside thesis/async: exact and the same on every run
  of virtual time. `at` counts from the first event the writer sees, never from the origin of `Instant`
  (unknown, and a rounded-up second on virtual time). The wall clock is read once, for `start` of the header,
  from the injected `ClockInterface`, so a test fixes it.
- A writer of the events (`NdjsonWriter`, `TextLog`) has a method marked `#[Listener]` for every event class
  of `Thesis\Async\Trace`,
  never one of `Event`: an event added to async in a minor reaches an older async-trace, and the dispatcher
  does not route it to methods of other classes (`takesNoEventOfNewerAsyncItDoesNotKnow`). A new event of async
  gets its method here in the same change: `unheardEvents()` of `tests/helpers.php` reads the `@phpstan-sealed`
  list of `Event` (PHPStan keeps it complete), and `listensToEveryEventOfAsync` of each writer fails on a class
  the dispatcher does not route to it.
- A tool listens only to the events it needs: `observe()` does not build an event nobody gets, and `Suspended`
  and `Resumed` cost a backtrace per wait. `WhoCancelled` never listens to the waits (`listensToNoWaitsAndRuns`).
- A tool reports to the dispatcher of the trace that holds it, the second argument of its listener
  (`onEnded(Ended $event, Dispatcher $dispatcher)`), a class per finding under the sealed `Report`.
  A report holds only formatted values, so its listener may keep it. It is dispatched inside a listener
  of `observe()`, so the rules of that listener are the rules of the listener of the report.
  Rejected: finding the events by the files of `src/Trace/` of async, it guessed classes by the case of a file name,
  and `class_exists()` on `api.php` included it again (composer's PSR-4 uses `include`): a fatal redeclaration.
- The lines of `TextLog` are a public format too, compared by the tests of its users: a change of a line changes
  the table of `README.md` and is a break in `CHANGELOG.md`.
- Every string goes through `JSON_INVALID_UTF8_SUBSTITUTE`: a name, a message and a path come from the user
  and may be any bytes, and an exception from `json_encode()` would break a listener.

## Decisions

- One package for all the tools of the trace, named `thesis/async-trace`: the trace is of thesis/async alone.
  Rejected: the writer inside thesis/async (the tools would have to move out later anyway, and async stays only
  the events), `thesis/trace` (it reads as tracing in general, like OpenTelemetry).
- A header line carries the format, the version and the wall-clock start, the events carry no version.
  Rejected: `"v":1` on every line, it lengthens every line for what the first one says.
- The event is named by the short name of its class (`TaskCreated`), the same as in the PHP API.
- A method per event marked `#[Listener]` of thesis/dispatcher: each method says what it listens to, and the dispatcher
  routes by the type. The price: an event that the listener does not know is not written, and a missing method
  is found by a test, not by PHPStan. Rejected: `__invoke(Event)` with a `match` over the sealed `Event`, PHPStan
  would point at a missing arm, but an older async-trace would throw `UnhandledMatchError` on a newer event of async;
  a `default` that skips it hides a missing arm from PHPStan (`php.md`). Rejected: `__invoke()` of the union
  of the known events with a `match`, the same routing as the methods with ten classes in one signature.
  Rejected: a fallback listener of `Event`, it would get the known events too and write them twice.
- The text is made for reading and for comparing in a test, not for parsing: a name or a callee may hold ` in `,
  an error is the short name of its class. A tool reads the NDJSON. A name and a value are PHP literals
  of thesis/formatter (`'fetch user'`), so a name with spaces stays one; the seconds are exact (`1.000000001`),
  not `%g`, which rounds to six digits and would hide an order of events a nanosecond apart.
- thesis/async keeps its own `tests/TraceLog.php` of the same shape: its tests check the events, and a dev dependency
  of async on async-trace would be a cycle in the split repositories.
- A location is `{file, line}` of the full path, not `"file:line"` (a reader would split it, and a path may hold
  `:`) and not `Location::$shortFile` (it depends on the root of the project that writes the trace).
- Reports go to the dispatcher of the trace, among the events: one dispatcher is built for a program, a listener
  takes the reports of its type (`CancelledBy` alone), and a tool may listen to the reports of another one.
  The price: a report is dispatched before the listeners after the tool get the event that has caused it.
  Rejected: a dispatcher of reports given to the constructor of a tool, two dispatchers to build and one more
  argument per tool. Rejected: a `callable(Report): void`, no routing by type and no `listens()`.
- `WhoCancelled` reports when a scope ends as cancelled, not when it is cancelled: the question is asked when
  `Cancelled` comes out, and then the scope is known whole. A scope under a shield that never ends gets nothing.
  The reason is formatted at `ScopeCancelled`, while the scope that has cancelled is alive, and shared by every
  scope it has reached. Trap: a task that fails ends before its group is cancelled by its failure, so a failed task
  is kept until its group ends to name it in the reason. The error comes from `ScopeCancelled::$error`: the body
  of a group ends after the cancellation by its failure. Rejected: keeping every ended scope that may be a `by`,
  it grows forever in a server.
- Not yet: following a chain of causes, such as a task failed by the `TaskCancelled` of a task it awaited,
  cancelled in its turn. The first cause covers the deadlines, the calls and the failures.
- An error is its class, message, file and line, without the previous ones and the trace:
  a line per event must stay short, and the cause of a cancellation is in `ScopeCancelled`, not in `Cancelled`.
