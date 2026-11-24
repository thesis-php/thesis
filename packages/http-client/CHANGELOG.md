# Changelog

This changelog records notable changes to the package.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Send an HTTP or HTTPS GET by URL, or send a request with streaming bodies and trailers through `Client::send()`.
- `Client::withResponse()` closes the body when the response callback returns or throws.
- Limit concurrent connections per origin and pool persistent connections across exchanges.
- Customize requests and responses with ordered middleware. The defaults follow redirects and decode gzip and deflate.
- Enable bounded retries of idempotent requests with `RetryRequests`.
- Submit URL-encoded and streaming multipart forms.
- Use a custom socket connector or tunnel requests through an HTTP CONNECT proxy.
