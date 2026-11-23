# Changelog

This changelog records notable changes to the package.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Set the endpoint and HTTP limits in `Config`. Use `TcpEndpoint` or `UnixEndpoint` to tune socket settings.
  Start listening with `Server::run($group)` and stop accepting with `close()`.
- Serve TCP and Unix connections concurrently with a bounded number of open sockets.
- Stream HTTP/1 requests and responses with fixed-length and chunked bodies, trailers and keep-alive.
- Bound request sizes and connection I/O waits. Stop accepting connections and give active exchanges time to finish.
