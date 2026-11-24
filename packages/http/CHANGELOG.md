# Changelog

This changelog records notable changes to the package.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Clients and servers share `Request` and `Response`. Metadata copies share a single `takeBody()` handoff.
- Forwarding a message preserves the reader and its final trailer fields.
- Share immutable headers, ordered query parameters, HTTP methods and status codes across roles.
- Read streaming bodies incrementally or collect a whole unread payload. Replayable bodies can open independent readers.
- Intercept client and server exchanges with their respective middleware contracts.
