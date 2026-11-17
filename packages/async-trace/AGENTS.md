# thesis/async-trace

The tools around `Trace\observe()` of thesis/async. thesis/async keeps only the events, everything built on them
lives here. The plan of the tools is `docs/trace-ideas.md` of the monorepo. The format is described in `README.md`.

This file is for agents changing this package. It is not loaded while an agent works in another package,
so how to *use* this package belongs in `README.md` or in a skill under `skills/<skill-name>/SKILL.md`.

## Invariants that must not be broken

- A listener only observes (`observe()` of thesis/async): `NdjsonWriter` never throws for a failed write (it counts
  it in `$lost`), never suspends (a synchronous `fwrite()`, no thesis/io), and keeps nothing of an event but
  the `Instant` of the first one: a value, an error and a task are formatted inside `__invoke()`.
- The NDJSON is the base every other tool reads, and `README.md` is its specification: a change of the format
  changes the table there in the same commit. A field added or an event added keeps `VERSION`, a field removed
  or one whose meaning changes bumps it.
- `at` and `duration` are integer nanoseconds, like the time inside thesis/async: exact and the same on every run
  of virtual time. `at` counts from the first event the writer sees, never from the origin of `Instant`
  (unknown, and a rounded-up second on virtual time). The wall clock is read once, for `start` of the header,
  from the injected `ClockInterface`, so a test fixes it.
- `NdjsonWriter` has a method marked `#[Listener]` for every event class of `Thesis\Async\Trace`, and a new event
  of async gets its method here in the same change: `listensToEveryEventOfAsync` reads the `@phpstan-sealed` list
  of `Event` (PHPStan keeps it complete) and fails on a class the dispatcher does not route to the writer.
  Rejected: finding the events by the files of `src/Trace/` of async, it guessed classes by the case of a file name,
  and `class_exists()` on `api.php` included it again (composer's PSR-4 uses `include`): a fatal redeclaration.
- Every string goes through `JSON_INVALID_UTF8_SUBSTITUTE`: a name, a message and a path come from the user
  and may be any bytes, and an exception from `json_encode()` would break a listener.

## Decisions

- One package for all the tools of the trace, named `thesis/async-trace`: the trace is of thesis/async alone.
  Rejected: the writer inside thesis/async (the tools would have to move out later anyway, and async stays only
  the events), `thesis/trace` (it reads as tracing in general, like OpenTelemetry).
- A header line carries the format, the version and the wall-clock start, the events carry no version.
  Rejected: `"v":1` on every line, it lengthens every line for what the first one says.
- The event is named by the short name of its class (`TaskCreated`), the same as in the PHP API.
- A method per event marked `#[Listener]` of thesis/dispatcher, not `__invoke(Event)` with a `match`: each method
  says what it listens to, and the dispatcher routes by the type. The price: an event that the writer does not know
  is not written. Rejected: a fallback listener of `Event`, it would get the known events too and write them twice,
  and a check of the known classes inside it is the `match` again.
- A location is `{file, line}` of the full path, not `"file:line"` (a reader would split it, and a path may hold
  `:`) and not `Location::$shortFile` (it depends on the root of the project that writes the trace).
- An error is its class, message, file and line, without the previous ones and the trace:
  a line per event must stay short, and the cause of a cancellation is in `ScopeCancelled`, not in `Cancelled`.
