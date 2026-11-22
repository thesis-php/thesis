# Changelog

This changelog records notable changes to the package.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `group()` starts a task group with tasks and daemons. The task tree controls cancellation.
- `group()` runs an event loop on PHP 8.6's `Io\Poll` from `{main}`. The loop throws `Deadlock` when
  nothing can wake a waiting task.
- `all()`, `allSettled()`, `race()` and `some()` run tasks together. `each()` and `map()` limit concurrency.
- `timeout()`, `timeoutOr()` and `remaining()` manage deadlines. `shield()` protects cleanup from cancellation.
- `delay()` waits on a timer, and `checkpoint()` yields to the event loop and checks cancellation.
- `suspend()` lets callers build their own waiting primitives. `onCancel()` and `isCancelled()` work without a wait.
- `Deferred`, `Channel` and `ScopedValue` let tasks share results, send values and read scoped state.
- `Once` shares a lazily computed value or error and can retry after cancellation.
- `suppressed()` retrieves failures that a group did not rethrow.
- `Mutex`, `Semaphore` and their keyed variants coordinate tasks. The package also provides
  `synchronized()`, rate limiters and `Backoff`.
- `StreamWatcher` and `Pacer` support non-blocking streams. `awaitSignal()` and `trapSignals()` handle signals.
- `Instant` reads monotonic time. `virtualTime()` and `burnCpu()` control time in tests, and a seed shuffles
  the fibers ready in each tick.
- `Trace\observe()` dispatches scope events. The events record cancellation causes, spawn and wait sites,
  and task and group names from explicit arguments, task keys or the group-creating function.
- `Trace\watchdog()` schedules a callback without keeping the event loop alive.
