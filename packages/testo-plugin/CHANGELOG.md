# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Testo plugins for thesis packages: tests on the virtual time of thesis/async, with a random seed of the order
  of a tick that a failed test prints and `THESIS_SEED` replays.
- `EventLoopPlugin`: a test runs in the event loop of thesis/async, on the real clock.
- `PhpErrorsPlugin`: a PHP error that a test does not suppress fails the test as `ErrorException`.
