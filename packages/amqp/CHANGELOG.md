# Changelog

This changelog records notable changes to the package.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The package moved to the [Thesis monorepo](https://github.com/thesis-php/thesis) and is written anew
on [thesis/async](https://github.com/thesis-php/async), [thesis/io](https://github.com/thesis-php/io)
and [thesis/net](https://github.com/thesis-php/net): the API of 1.x is gone, see `README.md`.

### Changed

- **BC break:** The package now requires 64-bit PHP 8.5+.

### Fixed

- Concurrent calls to `confirmSelect()` and `txSelect()` send one mode selection. A conflicting call throws
  `LogicException` locally and the channel stays open.
