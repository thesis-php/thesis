# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- `VirtualTime\run()` starts its clock at the current `now()` rounded up to a whole second instead of zero,
  so a time captured before it stays valid inside.
- `delay()` of zero or less waits as `delay(0)` instead of failing in Revolt.
- `timeout()`, `timeoutOr()` and `delay()` reject `NAN` with `\ValueError`.

### Fixed

- `LocalRateLimiter` and `LocalSlidingWindowRateLimiter` no longer hang on virtual time at rates that are not exact
  in binary (`perSecond: 10`): they keep absolute times and sleep once per call.
