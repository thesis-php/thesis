# Changelog

This changelog records notable changes to the package.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- The `Cache` interface uses `Time\Duration` for TTLs. `LocalCache` evicts the least recently used entries
  and expires entries lazily. `AtomicCache` locks writes and prevents stampedes with a keyed mutex.
- `PrefixCache` prefixes cache keys. `NullCache` never stores values.
- `LocalCache` implements `Countable` and counts only unexpired entries.
