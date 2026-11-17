# Thesis Async Trace

[![PHP Version Requirement](https://img.shields.io/packagist/dependency-v/thesis/async-trace/php)](https://packagist.org/packages/thesis/async-trace)
[![GitHub Release](https://img.shields.io/github/v/release/thesis-php/async-trace)](https://github.com/thesis-php/async-trace/releases)

Tools around the trace of [thesis/async](https://github.com/thesis-php/async): its events written as NDJSON,
one line per event, and as lines of text for tests, who has cancelled a scope, and code that blocks the others.

```php
use Thesis\Dispatcher;
use Thesis\AsyncTrace\NdjsonWriter;
use function Thesis\Async\Trace\observe;

$file = fopen('trace.ndjson', 'w');
observe(new Dispatcher([new NdjsonWriter($file)]), $main);
```

## Installation

```shell
composer require thesis/async-trace
```

Requires 64-bit PHP 8.5+. No extensions.

## `NdjsonWriter`

A `Subscriber` of thesis/dispatcher with a listener of every event of `Trace\observe()`: give it to the dispatcher
as it is, among other listeners if needed. It writes a header line before the first event, then a line
per event, and keeps nothing of them: a value, an error and a task are formatted at once.

It writes synchronously, as a listener must: pass a blocking stream (a file, `php://stderr`).
It never throws: a line it could not write is counted in `$writer->lost`. Wrap `observe()` in
`VirtualTime\run()` for a trace that is the same on every run, `start` of the header aside
(pass a `Thesis\Time\FrozenClock` as the second argument to fix it too).

## `TextLog`

A `Subscriber` with a listener of every event like `NdjsonWriter`, that keeps each as a line of text,
`<seconds> #<scope> <what>`. On virtual time the lines are the same on every run, so a test compares them
with what it expects:

```php
use Testo\Assert;
use Thesis\AsyncTrace\TextLog;

$log = new TextLog();
observe(new Dispatcher([$log]), static fn() => timeoutOr(sec(2), static fn() => delay(sec(5))));

Assert::same($log->lines, [
    '0 #1 timeout 2s in root',
    '0 #1 suspended in Thesis\Async\delay',
    '2 #1 cancelled: Deadline by #1, reached #1',
    '2 #1 resumed by Cancelled',
    '2 #1 threw Cancelled',
]);
```

The lines stay in memory: it is for a test or a short program. The seconds are exact, without trailing zeros
(`0`, `1.5`, `1.000000001`). A scope is `#<number>`, `root` for none. A name and a value are PHP literals
of [thesis/formatter](https://github.com/thesis-php/formatter), an error is the short name of its class:

| Event | Line |
|---|---|
| `GroupCreated` | `group 'checkout' in root`, `all in #1, cancelled` (the function that has created it) |
| `TaskCreated` | `task 'fetch' in #1`, `daemon in #1` |
| `TimeoutCreated` | `timeout 1.5s in #1` |
| `ShieldCreated` | `shield in #1` |
| `TaskStarted` | `started` |
| `Suspended` | `suspended in Thesis\Async\delay`, `suspended` if the caller is unknown |
| `Resumed` | `resumed`, `resumed by Cancelled` |
| `ScopeCancelled` | `cancelled: Failure by #3, reached #1 #2`, `cancelled: Handle by #1 in Thesis\Async\Task::cancel, reached #2` |
| `Joining` | `joining` |
| `Ended` | `returned 42`, `threw RuntimeException` |

A line is for reading and comparing, not parsing: a tool reads the NDJSON.

## `WhoCancelled`

`Cancelled` carries nothing: a task that ends cancelled does not tell by whom. `WhoCancelled` does: each scope
that ends as cancelled gets a `CancelledBy` report, with what has cancelled the scope and where it is written.
The report goes to the same dispatcher as the events of the trace:

```php
use Thesis\AsyncTrace\CancelledBy;
use Thesis\AsyncTrace\WhoCancelled;

$print = static function (CancelledBy $report) {
    fwrite(STDERR, $report . "\n");
};
observe(new Dispatcher([new WhoCancelled(), $print]), $main);
// #3 task 'fetch' at /app/src/main.php:12 is cancelled by the deadline of timeout 2s at /app/src/main.php:10
```

| Cause | Reason |
|---|---|
| `Deadline` | `the deadline of timeout 2s at /app/src/main.php:10` |
| `Handle` | `Thesis\Async\Task::cancel() at /app/src/main.php:20 in group at /app/src/main.php:8` |
| `CancelTasks` | `Thesis\Async\Group::cancelTasks() at /app/src/main.php:21 in group at /app/src/main.php:8` |
| `Failure` | `the failure of task 'fetch' at /app/src/main.php:12: RuntimeException 'boom' at /app/src/Api.php:40` |
| `DaemonsAfterTasks` | `the end of the tasks of group at /app/src/main.php:8` |

A scope created in a cancelled one (a task spawned after `cancelTasks()`, a group under an expired deadline) gets
the reason of the cancellation it was created under. The report has the fields too: `scope`, `what`, `at`
(the moment of the cancellation), `cause`, `by` (the number of the scope that has cancelled) and `reason`.

It listens only to the scopes being created, cancelled and ended, not to the waits, which cost a backtrace each.
It forgets a scope when the scope ends, fit for a process that lives long. A listener of the reports is called
inside a listener of `observe()`, with the same rules: it must not throw or suspend the fiber. A report is dispatched
at once, before the listeners that stand after `WhoCancelled` get the `Ended` that has caused it: put a writer
of the events (`NdjsonWriter`, `TextLog`) before it to keep the end before the report.

## `BlockingDetector`

Code that runs without a wait holds every other task: a synchronous call to a database, a long loop, a blocking
`file_get_contents()`. `BlockingDetector` reports each run longer than its threshold as `Blocked`, with the waits
around it, to the same dispatcher as the events:

```php
use Thesis\AsyncTrace\Blocked;
use Thesis\AsyncTrace\BlockingDetector;
use function Thesis\Time\ms;

$print = static function (Blocked $report) {
    fwrite(STDERR, $report . "\n");
};
observe(new Dispatcher([new BlockingDetector(threshold: ms(50)), $print]), $main);
// #2 task 'work' at /app/src/main.php:8 has run 0.12s without a wait: from Thesis\Async\delay()
// at /app/src/main.php:9 to Thesis\Async\Channel::receive() at /app/src/main.php:14
```

A run begins when a task starts or a wait is over, and ends at the next wait of any scope, at the join of a group
or at the end of a task: `from` and `to` of the report say which. The report has `scope`, `what`, `at`
(the beginning of the run) and `duration` too. The time is that of the events: under `VirtualTime\run()`
code takes no time, and a test makes a run long by `VirtualTime\advance()`.

Not seen: a run that never ends (an endless loop), code after the join of a group up to its next wait,
code outside the scope tree. The root scope is not in the trace, its waits have no events: code that runs
in the callable of `observe()` itself, outside any group, is never reported. Run it in a task:

```php
use Thesis\Async\Group;
use function Thesis\Async\group;

observe($dispatcher, static fn() => group(static fn(Group $group) => $group->spawn($main, name: 'main')));
```

It listens to the waits, so each costs a backtrace: a tool for debugging, not for every request in production.

## The format, version 1

The header:

```json
{"format":"thesis/async-trace","version":1,"start":"2026-11-17T04:38:36.250000+00:00"}
```

`start` is the wall-clock time of the first event. Every next line has `event` (the short name of the class
of the event), `scope` (its number) and `at` (nanoseconds since the first event, an integer), then the fields
of its class:

| `event` | Fields |
|---|---|
| `GroupCreated` | `parent`, `function`, `name`, `cancelled`, `location` |
| `TaskCreated` | `group`, `daemon`, `name`, `task`, `cancelled`, `location` |
| `TimeoutCreated` | `parent`, `duration` (nanoseconds), `cancelled`, `location` |
| `ShieldCreated` | `parent`, `cancelled`, `location` |
| `TaskStarted` | |
| `Suspended` | `caller` |
| `Resumed` | `error` |
| `ScopeCancelled` | `cause` (a case of `CancelCause`: `Deadline`, `Failure`, ...), `by`, `reached`, `caller` (of `Task::cancel()` and `Group::cancelTasks()`), `error` (of `Failure`) |
| `Joining` | |
| `Ended` | `value`, `error` |

```json
{"event":"TaskCreated","scope":2,"at":0,"group":1,"daemon":false,"name":"fetch","task":"function@/app/src/main.php:12()","cancelled":false,"location":{"file":"/app/src/main.php","line":12}}
{"event":"Suspended","scope":2,"at":0,"caller":{"callee":"Thesis\\Async\\delay","file":"/app/src/main.php","line":13}}
{"event":"Ended","scope":2,"at":1000000000,"value":"42","error":null}
```

- A scope (`parent`, `group`, `by`, the items of `reached`) is its number, `null` for none.
- `location` is `{"file", "line"}`, `caller` is `{"callee", "file", "line"}`, `null` if unknown.
  The file is the full path.
- `value` and `task` are strings of [thesis/formatter](https://github.com/thesis-php/formatter): `"42"`,
  `"list{1, 2}"`, `"App\\Service::handle()"`. `value` is `null` if the scope has thrown.
- `error` is `{"class", "message", "file", "line"}` or `null`. `Thesis\Async\Cancelled` is a cancellation.
- A string that is not valid UTF-8 has its invalid bytes replaced by U+FFFD.
- An event of a newer thesis/async that this version does not know is not written: update thesis/async-trace with it.

A new field or a new event keeps the version: a reader ignores what it does not know. A field that is removed
or changes its meaning makes a new version.
