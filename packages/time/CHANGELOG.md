# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Requires PHP 8.2+, 64-bit or 32-bit: the polyfill of `Time\Duration` serves PHP 8.2 to 8.5 and has the range
  of the class on 32-bit PHP, the conversions throw `Time\TimeException` for a result above `PHP_INT_MAX`.

### Fixed

- A function given NAN throws `ValueError` without a warning of PHP 8.5 first.

## [0.3.0] - 2026-11-16

The package is new and has nothing in common with the older `thesis/time` 0.1 and 0.2,
which is now [thesis/time-span](https://github.com/thesis-php/time-span).

### Added

- Functions for Time\Duration of PHP 8.6 and a polyfill of it for PHP 8.5, PSR-20 clocks.
