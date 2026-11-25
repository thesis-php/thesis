# Changelog

This changelog records notable changes to the package.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Log requests and server shutdown through the HTTP event subscriber.
- Run a local HTTP server with a usage hint at `GET /` and compare sequential and concurrent requests to `GET /{id}`, which echoes the decoded parameter, with curl.
