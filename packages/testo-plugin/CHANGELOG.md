# Changelog

This changelog records notable changes to the package.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Tests can run on thesis/async virtual time. A failing test prints the seed that ordered fibers within
  each tick. Set `THESIS_SEED` to replay the order.
- `EventLoopPlugin` runs tests in the thesis/async event loop on the real clock.
- `PhpErrorsPlugin` turns an unsuppressed PHP error into an `ErrorException` that fails the test.
