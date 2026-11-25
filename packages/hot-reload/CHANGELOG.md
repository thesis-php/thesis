# Changelog

This changelog records notable changes to the package.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The package was rewritten on Thesis Async and Process in the monorepo.

### Added

- `hot-reload` restarts a development server on `SIGHUP` or changes to paths passed with `--watch`,
  including after a crash or failed startup.
- Let the server finish its Ctrl+C handler while sharing the terminal with the supervisor.
