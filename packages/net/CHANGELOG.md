# Changelog

This changelog records notable changes to the package.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `Socket::tryRead()` reads available bytes immediately and returns null when it would have to wait.

- `Socket::tryWrite()` sends available plaintext bytes immediately and returns their count.
- The package provides non-blocking TCP and Unix sockets, TLS and asynchronous DNS.
  The task tree controls cancellation.
- Socket writes and half-closes run in call order. Closing during a TLS handshake raises `SocketClosed`.
- DNS cache entries expire when their earliest A or AAAA answer expires.
- `Server::awaitClose()` waits for listener shutdown without accepting a socket.
- `InetAddress::fromString()` parses a numeric IP address with a port.
