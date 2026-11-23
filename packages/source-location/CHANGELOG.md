# Changelog

This changelog records notable changes to the package.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.1] - 2026-11-23

### Changed

- The package now requires PHP 8.4+ instead of 64-bit PHP 8.5+.

## [0.1.0] - 2026-11-17

### Added

- `Location` of a file and a line: the code of `eval()` resolved to the outermost `eval()`, a short path.
- `caller()`: the first call of the stack written outside a library, where it is and what it calls.
