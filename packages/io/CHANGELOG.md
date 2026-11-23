# Changelog

This changelog records notable changes to the package.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- The package provides a byte buffer with typed encoding, non-blocking streams and standard I/O.
- `SizedReader` exposes the total size in bytes. `StringReader` reads bytes from a string in memory.
- `Reader::read()` throws `ConcurrentRead` if a read is already in progress on the same reader.
