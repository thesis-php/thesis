# Changelog

This changelog records notable changes to the package.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Clients and servers share `Request` and `Response`. Requests hold a method, a `Uri\Rfc3986\Uri`,
  headers and a body. Responses hold a status, headers and a body.
- `Method` and `Status` define constants for common methods and status codes.
- `Headers` is immutable. It treats names as case-insensitive and stores several lines per name.
  It throws `\ValueError` for an invalid name or a forbidden control character in a value.
- `Headers::$isEmpty` reports whether it holds any fields.
- `Request::$query` exposes ordered query parameters. Replacing the query on a copy updates its URI.
- Pass a string or a `Body` to a message constructor. `StringBody` accepts a string with optional trailer fields.
- `Body::$mayHaveTrailers` signals possible trailers before reading. `Body::discard()` returns the final fields at EOF.
- `Body::close()` releases body resources.
- Collect the remaining response body with a byte limit through `Response::buffer()`.
- Open an independent reader for string uploads through `StringBody::fresh()`.
