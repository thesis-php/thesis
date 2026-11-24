# Changelog

This changelog records notable changes to the package.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The HTTP messages, client, server and HTTP/1 codec ship together as `thesis/http`.

### Added

- Clients and servers share `Request` and `Response`. Metadata copies share a single `takeBody()` handoff.
- Forwarding a message preserves the reader and its final trailer fields.
- Share immutable headers, ordered query parameters, HTTP methods and status codes across roles.
- Read streaming bodies incrementally or collect a whole unread payload. Replayable bodies can open independent readers.
- Intercept client and server exchanges with `Client\Middleware` and `Server\Middleware`.
- Send an HTTP or HTTPS GET by URL, or send a request with streaming bodies and trailers through `Client::send()`.
- `Client::withResponse()` closes the body when the response callback returns or throws.
- Limit concurrent connections per origin and pool persistent connections across exchanges.
- Customize requests and responses with ordered middleware. The defaults follow redirects and decode gzip and deflate.
- Enable bounded retries of idempotent requests with replayable uploads with `RetryRequests`.
- Submit URL-encoded and streaming multipart forms.
- Use a custom socket connector or tunnel requests through an HTTP CONNECT proxy.
- Set the endpoint and HTTP limits in `Config`. Use `TcpEndpoint` or `UnixEndpoint` to tune socket settings.
  Start listening with `Server::start($group)` and wait for active exchanges with `stop()`.
- Serve TCP and Unix connections concurrently with a bounded number of open sockets.
- Stream HTTP/1 requests and responses with fixed-length and chunked bodies, trailers and keep-alive.
  Set `maxRequestsPerConnection` to limit keep-alive reuse.
- Bound request sizes and connection I/O waits. Stop accepting connections and give active exchanges time to finish.
- `FixedLengthReader` reads exactly the declared size and reports a premature EOF. Its read-only
  remaining byte count reaches zero on delivery of the last byte.
- The HTTP/1 body decoders can be passed directly to a message with their length and trailers.
- `RequestHead` and `ResponseHead` handle HTTP/1.1 heads and record framing and persistence.
  `parse()` rejects ambiguous input, `of()` builds a head from a message, and `toString()` writes the head.
- `ChunkedReader`, `encodeChunk()` and `encodeLastChunk()` handle chunked bodies with trailers.
- `ResponseHead` preserves `Content-Length` as representation metadata for eligible `HEAD` and `304` responses.
