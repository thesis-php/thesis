# Changelog

This changelog records notable changes to the package.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.4.0] - 2026-11-20

### Added

- `toNsFloat()`, `toUsFloat()`, `toMsFloat()`, `toMinutesFloat()` and `toHoursFloat()`, like `toSecFloat()`.
  On 32-bit PHP `toNs()` throws above 2.1 seconds, `toNsFloat()` does not.
- `parseDuration()` reads a duration from a string like `1h30m`, `1.5s` or `250ms`, in the grammar
  of `time.ParseDuration()` of Go: the reverse of `formatDuration()`.
- `ratio()` divides a duration by a duration into a float: a progress, a rate, the intervals that have passed.
- `roundDurationTo()` rounds a duration to a multiple of a unit: to `ms(1)` for a log.
- `roundTimeTo()` rounds a time to a multiple of a unit since 1970: the start of a window of 5 minutes.

### Changed

- **BC break:** `toUs()`, `toMs()`, `toSec()`, `toMinutes()` and `toHours()` require a `\RoundingMode`.
  Pass `\RoundingMode::TowardsZero` to keep the old result.
- **BC break:** `format()` is renamed to `formatDuration()`.
- **BC break:** `between()` is renamed to `durationBetween()`, `shift()` to `shiftTime()`.

### Removed

- **BC break:** `isPositive()` and `isNegative()`. Use `$duration > sec(0)` and `$duration->negative`.
- **BC break:** `shortest()` and `longest()`. Use `min()` and `max()` of PHP, which compare durations by value.

### Fixed

- `toSecFloat()` returns the float nearest to the duration: `toSecFloat(ms(2530))` was `2.5300000000000002`.
- `multiply()` by a finite float throws `Time\TimeException`, not `\ValueError`, when the product is too large
  for a float.

## [0.3.1] - 2026-11-18

### Changed

- Requires PHP 8.2.2+, 64-bit or 32-bit: the polyfill of `Time\Duration` serves PHP 8.2 to 8.5,
  below PHP 8.4 `\RoundingMode` comes from `symfony/polyfill-php84`.
- On 32-bit PHP a duration is up to about 68 years, and `toNs()`, `toUs()`, `toMs()` and `toSec()` throw
  `Time\TimeException` for a result above `PHP_INT_MAX`.

### Fixed

- A function given NAN throws `ValueError` without a warning of PHP 8.5 first.

## [0.3.0] - 2026-11-16

The package is new and has nothing in common with the older `thesis/time` 0.1 and 0.2,
which is now [thesis/time-span](https://github.com/thesis-php/time-span).

### Added

- Functions for Time\Duration of PHP 8.6 and a polyfill of it for PHP 8.5, PSR-20 clocks.
