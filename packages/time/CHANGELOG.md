# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `parse()` reads a duration from a string like `1h30m`, `1.5s` or `250ms`, as `time.ParseDuration()` of Go:
  the reverse of `format()`.

### Removed

- **BC break:** `isPositive()` and `isNegative()`. Use `$duration > sec(0)` and `$duration->negative`.

### Changed

- `shortest()` and `longest()` take no named arguments (`@no-named-arguments`), PHPStan reports them.

## [0.4.0] - 2026-11-19

### Added

- `toUsFloat()`, `toMsFloat()`, `toMinutesFloat()` and `toHoursFloat()`, like `toSecFloat()`.

### Changed

- **BC break:** `toUs()`, `toMs()`, `toSec()`, `toMinutes()` and `toHours()` require a `\RoundingMode`.
  Pass `\RoundingMode::TowardsZero` to keep the old result.

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
