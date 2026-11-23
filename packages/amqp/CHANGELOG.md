# Changelog

This changelog records notable changes to the package.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The package moved to the [Thesis monorepo](https://github.com/thesis-php/thesis) and is written anew
on [thesis/async](https://github.com/thesis-php/async), [thesis/io](https://github.com/thesis-php/io)
and [thesis/net](https://github.com/thesis-php/net): the API of 1.x is gone, see `README.md`.

### Changed

- **BC break:** The package now requires 64-bit PHP 8.5+.

### Fixed

- Concurrent calls to `confirmSelect()` and `txSelect()` send one mode selection. A conflicting call throws
  `LogicException` locally and the channel stays open.
- `publish()` and `publishBatch()` honour resource alarms, channel closure and concurrent confirm selection.
  Deliveries can still be acknowledged during an alarm.
- Concurrent `transactional()` calls isolate their publications and acknowledgements. Cancelling a multiple
  acknowledgement before its write allows an individual acknowledgement to proceed.
- `Consumer::cancel()` sends one cancellation for concurrent callers. Reusing a consumer tag leaves old handles inert.
