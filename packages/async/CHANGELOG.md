# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Structured concurrency for PHP fibers: `group()` with tasks and daemons, cancellation is a property of the task tree.
- An event loop of its own on `Io\Poll` of PHP 8.6, run by `group()` in `{main}`: `Deadlock` when nothing can wake
  a task.
- `all()`, `allSettled()`, `race()` and `some()`, bounded concurrency with `each()` and `map()`.
- Deadlines with `timeout()`, `timeoutOr()` and `remaining()`, cleanup under `shield()`, `delay()` and `checkpoint()`.
- `suspend()` for primitives of your own, `onCancel()` and `isCancelled()` for code that does not wait through it.
- `Deferred`, `Channel`, `ScopedValue` and the failures that were not rethrown in `suppressed()`.
- `Mutex`, `Semaphore` and their keyed variants with `synchronized()`, rate limiters, `Backoff`.
- `StreamWatcher` and `Pacer` for non-blocking streams, `awaitSignal()` and `trapSignals()` for signals.
- `Instant` and tests on virtual time with `virtualTime()` and `burnCpu()`, the fibers of a tick shuffled by a seed.
- `Trace\observe()`: the events of the scope tree to the listeners of a `Thesis\Dispatcher`, who cancelled,
  where and why, where each task is spawned and waits, tasks named by `Group::spawn($task, name: ...)`
  or by their keys in `all()` and `race()`, groups by `group($body, name: ...)` and by the function that created them.
- `Trace\watchdog()`: a callback after a delay that does not keep the event loop alive.
