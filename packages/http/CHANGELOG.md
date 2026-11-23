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
- `Request::$query` exposes ordered query parameters. Replacing the query on a copy updates its URI.
- `Body` reads bytes and exposes trailer fields. Pass a string, a reader or another message's body to a constructor.
- `Body::discard()` consumes the remaining bytes and returns the trailers. `Body::close()` releases a closable reader.
- `Http1\FixedLengthReader` reads exactly the declared size and reports a premature EOF. Its read-only
  remaining byte count reaches zero on delivery of the last byte.
- `Http1\RequestHead` and `Http1\ResponseHead` handle HTTP/1.1 heads and record framing and persistence.
  `parse()` rejects ambiguous input, `of()` builds a head from a message, and `toString()` writes the head.
- `Http1\ChunkedReader`, `Http1\encodeChunk()` and `Http1\encodeLastChunk()` handle chunked bodies with trailers.
