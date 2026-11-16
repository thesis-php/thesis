# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `Cache` interface with `Time\Duration` TTLs, `LocalCache` (LRU, lazy expiry) and `AtomicCache`
  (stampede protection by a keyed mutex, locked writes).
- `PrefixCache` and `NullCache`.
- `LocalCache` is `Countable`: the number of entries that have not expired.
