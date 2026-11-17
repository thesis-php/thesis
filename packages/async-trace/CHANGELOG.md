# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `NdjsonWriter`: a listener of `Trace\observe()` of thesis/async that writes every event as a line of JSON.
- `TextLog`: a listener that keeps every event as a line of text, for tests on virtual time.
- `WhoCancelled`: a `CancelledBy` report for each scope that ends as cancelled, with the deadline, the call
  or the failure that has cancelled it.
