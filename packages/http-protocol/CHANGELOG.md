# Changelog

This changelog records notable changes to the package.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `FixedLengthReader` reads exactly the declared size and reports a premature EOF. Its read-only
  remaining byte count reaches zero on delivery of the last byte.
- The HTTP/1 body decoders can be passed directly to a message with their length and trailers.
- `RequestHead` and `ResponseHead` handle HTTP/1.1 heads and record framing and persistence.
  `parse()` rejects ambiguous input, `of()` builds a head from a message, and `toString()` writes the head.
- `ChunkedReader`, `encodeChunk()` and `encodeLastChunk()` handle chunked bodies with trailers.
- `ResponseHead` preserves `Content-Length` as representation metadata for eligible `HEAD` and `304` responses.
