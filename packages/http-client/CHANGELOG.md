# Changelog

This changelog records notable changes to the package.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Reuse persistent connections for requests to the same origin after consuming the response body.

- Send HTTP and HTTPS requests with streaming bodies and trailers through `Client::send()`.
- `Client::withResponse()` closes the body when the response callback returns or throws.
