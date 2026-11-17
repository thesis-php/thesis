# Thesis Async Trace

[![PHP Version Requirement](https://img.shields.io/packagist/dependency-v/thesis/async-trace/php)](https://packagist.org/packages/thesis/async-trace)
[![GitHub Release](https://img.shields.io/github/v/release/thesis-php/async-trace)](https://github.com/thesis-php/async-trace/releases)

Tools around the trace of [thesis/async](https://github.com/thesis-php/async): its events written as NDJSON,
one line per event, and as lines of text for tests.

```php
use Thesis\Dispatcher;
use Thesis\AsyncTrace\NdjsonWriter;
use function Thesis\Async\Trace\observe;
use function Thesis\Dispatcher\listenersOf;

$file = fopen('trace.ndjson', 'w');
observe(new Dispatcher(listenersOf(new NdjsonWriter($file))), $main);
```

## Installation

```shell
composer require thesis/async-trace
```

Requires 64-bit PHP 8.5+. No extensions.

## `NdjsonWriter`

A listener of every event of `Trace\observe()`, a method per event marked `#[Listener]`: give it to the dispatcher
by `listenersOf()`, among other listeners if needed. It writes a header line before the first event, then a line
per event, and keeps nothing of them: a value, an error and a task are formatted at once.

It writes synchronously, as a listener must: pass a blocking stream (a file, `php://stderr`).
It never throws: a line it could not write is counted in `$writer->lost`. Wrap `observe()` in
`VirtualTime\run()` for a trace that is the same on every run, `start` of the header aside
(pass a `Thesis\Time\FrozenClock` as the second argument to fix it too).

## `TextLog`

A listener of every event, a method per event like `NdjsonWriter`, that keeps each as a line of text,
`<seconds> #<scope> <what>`. On virtual time the lines are the same on every run, so a test compares them
with what it expects:

```php
use Testo\Assert;
use Thesis\AsyncTrace\TextLog;

$log = new TextLog();
observe(new Dispatcher(listenersOf($log)), static fn() => timeoutOr(sec(2), static fn() => delay(sec(5))));

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
