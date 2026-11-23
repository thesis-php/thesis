# Changelog

This changelog records notable changes to the package.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `NdjsonWriter` writes each `Trace\observe()` event as a JSON line.
- `TextLog` stores each event as a text line for tests on virtual time.
- `CancellationTracker` reports the deadline, call or failure that cancelled a scope in a `CancelOrigin`.
- `BlockingDetector` reports code that blocks the event loop past a threshold and records the surrounding waits.
- `LongWaitDetector` reports a wait when it reaches a threshold, while the wait is still pending and its dispatcher is being observed.
