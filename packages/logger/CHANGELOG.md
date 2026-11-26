# Changelog

This changelog records notable changes to the package.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `Logger` implements PSR-3 and writes directly by default.
- `Formatter\Json` writes NDJSON. `Formatter\Console` renders text with optional level colors and omits interpolated context fields by default.
- `Logger` accepts a PSR-20 clock, and both formatters preserve the clock's timezone offset.
- Exception context includes the source file and line.
- `Logger` casts `Stringable` messages to strings before interpolating placeholders.
