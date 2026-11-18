# thesis/async-trace

The tools around `Trace\observe()` of thesis/async. thesis/async keeps only the events, everything built on them
lives here. The format is described in `README.md`.

## Invariants that must not be broken

- A listener only observes (`observe()` of thesis/async): `NdjsonWriter` and `TextLog` never throw (a failed write
  is counted in `$lost`), never suspend (a synchronous `fwrite()`, no thesis/io), and keep nothing of an event but
  the `Instant` of the first one: a value, an error and a task are formatted inside the method of the event.
  A tool (`CancellationTracker`) keeps only strings, numbers and `Instant`s, and only of live scopes: a process that
  lives long must not grow with it.
- The NDJSON is the base every other tool reads, and `README.md` is its specification: a change of the format
  changes the table there in the same commit. A field added or an event added keeps `VERSION`, a field removed
  or one whose meaning changes bumps it.
- `at` and `duration` are integer nanoseconds, like the time inside thesis/async: exact and the same on every run
  of virtual time. `at` counts from the first event the writer sees, never from the origin of `Instant`
  (unknown, and a rounded-up second on virtual time). The wall clock is read once, for `start` of the header,
  from the injected `ClockInterface`, so a test fixes it.
- A writer of the events (`NdjsonWriter`, `TextLog`) has a listener for every event class of `Thesis\Async\Trace`,
  never one of `Event`: an event added to async in a minor reaches an older async-trace, and the dispatcher
  does not route it to methods of other classes (`takesNoEventOfNewerAsyncItDoesNotKnow`). A new event of async
  gets its method here in the same change: `unheardEvents()` of `tests/helpers.php` reads the `@phpstan-sealed`
  list of `Event` (PHPStan keeps it complete), and `listensToEveryEventOfAsync` of each writer fails on a class
  the dispatcher does not route to it.
- A writer and a tool are a `Subscriber` of thesis/dispatcher: the listeners are private methods given
  by `yield` in `listeners()`. PHPStan reports one that is not given as unused, and they are not the API
  of the class: a test gives the object to a dispatcher and dispatches the event.
- A tool listens only to the events it needs: `observe()` does not build an event nobody gets, and `Suspended`
  and `Resumed` cost a backtrace per wait. `CancellationTracker` never listens to the waits (`listensToNoWaitsAndRuns`).
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
- A method per event, given by `listeners()` of a `Subscriber`: each method says what it listens to,
  and the dispatcher routes by the type. The price: an event that the listener does not know is not written,
  and a missing method is found by a test, not by PHPStan. Rejected: `__invoke(Event)` with a `match`
  over the sealed `Event`, PHPStan would point at a missing arm, but an older async-trace would throw
  `UnhandledMatchError` on a newer event of async; a `default` that skips it hides a missing arm from PHPStan
  (`php.md`). Rejected: `__invoke()` of the union of the known events with a `match`, the same routing
  as the methods with ten classes in one signature.
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
  takes the reports of its type (`CancelOrigin` alone), and a tool may listen to the reports of another one.
  The price: a report is dispatched before the listeners after the tool get the event that has caused it.
  Rejected: a dispatcher of reports given to the constructor of a tool, two dispatchers to build and one more
  argument per tool. Rejected: a `callable(Report): void`, no routing by type and no `listens()`.
- A report is a noun of what is found (`CancelOrigin`, `BlockingRun`, `LongWait`), never a participle: the events
  of the same dispatcher are participles (`ScopeCancelled`). A tool is named by its role: a `Detector` applies
  a threshold, the `Tracker` explains every cancelled scope. Rejected: `Blocked`, which elsewhere means waiting
  (`Thread.State.BLOCKED`, Go's block profile); `Cancellation` and `CancelReason`, next to `Cancelled`,
  `CancelCause`, `Amp\Cancellation` and the field `reason`; `Stall`/`Hang`, synonyms that do not tell the CPU
  from the wait; `Who*` tools; a `Report` suffix, which events do not have either.
- `CancellationTracker` reports when a scope ends as cancelled, not when it is cancelled: the question is asked when
  `Cancelled` comes out, and then the scope is known whole. A scope under a shield that never ends gets nothing.
  The reason is formatted at `ScopeCancelled`, while the scope that has cancelled is alive, and shared by every
  scope it has reached. Trap: a task that fails ends before its group is cancelled by its failure, so a failed task
  is kept until its group ends to name it in the reason. The error comes from `ScopeCancelled::$error`: the body
  of a group ends after the cancellation by its failure. Rejected: keeping every ended scope that may be a `by`,
  it grows forever in a server.
  Trap: a scope created cancelled takes the first cancellation of what it is created in, and the scopes of a group
  are nested: body, tasks, daemons. A daemon takes the cause of the daemons (`DaemonsAfterTasks`), else of the tasks
  (`cancelTasks()` cancels the daemons too), else of the group; a task takes the cause of the tasks, else of the group.
- A tool handles only what thesis/async does: `ShieldCreated::$cancelled` is always false (a shield hides
  the cancellation), `Resumed` always comes before `Ended` of the scope (it is emitted in a `finally`),
  `ScopeCancelled::$reached` holds only scopes not cancelled before. Code for the other case can never run,
  and mutation testing shows it as a mutant no test kills. What is guarded is a scope the tool has not seen
  (events given by hand): it is named `#<id>` and never makes a tool fail.
- `BlockingDetector` takes the time of the events, not a clock of its own: `Instant::now()` is `hrtime()`
  outside virtual time, and under it `burnCpu()` is CPU work without a wait, which a test needs.
  Rejected: an injected monotonic clock, a second time that a test would have to move along with the virtual one.
- `BlockingDetector` does not see the root scope, and `README.md` tells to run the code in a task. Rejected:
  `Suspended`/`Resumed` with `scope: null` for the root. It would catch only waits of thesis/async called outside
  a group (the top of a script): amphp and raw Revolt callbacks wait through a `Suspension` of Revolt, never
  through `suspend()`. The price was a break of the type of `$scope`, a new `VERSION` of the NDJSON and a `from`
  that cannot tell the fibers of the root apart. Rejected: a group around the callable of `observe()`, it adds
  a scope, a join and a cancellation to every trace.
- A run of `BlockingDetector` follows the fiber, not the events of waits: code goes on without a `TaskStarted`
  or `Resumed` in two places, and a run begins there too. A group, a `timeout()` and a `shield()` created
  in the root run in the root fiber, whose waits have no events: a run begins at their creation and ends
  at their end (one created in another scope goes on with the run of its fiber). The join of a group waits
  for its tasks without `suspend()`, so no `Suspended` or `Resumed` comes: a run ends at `Joining`, else the run
  of the body would last until another fiber starts, and a run of the parent begins at `Ended` of the group.
  Trap: a run that begins while another is open has lost its end to a wait outside the tree (in the root scope):
  it is dropped, not reported with a wrong end.
- `LongWaitDetector` reports from a timer of the event loop: a hang brings no event. A `watchdog()` per wait
  not reported yet, armed in the loop of that wait and cancelled when it is resumed, so nothing is left
  in the loop after `observe()`. Not one timer for all: a nested loop (`virtualTime()` in a task) runs on a clock
  of its own and blocks the outer one, whose timer would neither fire nor measure the inner waits.
  The timers of the loop never fire early, so a due timer reports without checking again. A wait that ends exactly
  at the threshold is reported or not by the order of the fibers of one tick.
  Rejected: a timer per wait, a timer created and cancelled on every wait. Rejected: a repeating timer of a fixed
  period, a report late by up to the period and a wake-up of the loop while nothing waits long.
- Not yet: following a chain of causes, such as a task failed by the `TaskCancelled` of a task it awaited,
  cancelled in its turn. The first cause covers the deadlines, the calls and the failures.
- An error is its class, message, file and line, without the previous ones and the trace:
  a line per event must stay short, and the cause of a cancellation is in `ScopeCancelled`, not in `Cancelled`.
