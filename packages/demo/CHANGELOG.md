# Changelog

This changelog records notable changes to the package.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Serve HTTP on port 18080 and HTTPS on port 18443 with the bundled localhost certificate.
- Log requests and server shutdown through the HTTP event subscriber.
- Explore the linked routes at `GET /`, compare sequential and concurrent requests to `GET /wait/250ms`, and trigger a handler exception at `GET /error`.
- Run `app.php` with `hot-reload` to restart the server after edits.
