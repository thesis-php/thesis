# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The package moved to the [Thesis monorepo](https://github.com/thesis-php/thesis) and was written anew.

### Added

- `Dispatcher::listens()` tells whether any listener gets the events of a class.
- `Dispatcher\listenersOf()` gives the methods of an object marked `#[Dispatcher\Listener]` as listeners.

### Changed

- **BC break:** Requires 64-bit PHP 8.5+.
- **BC break:** The listeners are given to the constructor as an iterable: `new Dispatcher($listeners)`.
- **BC break:** A listener gets the events of the type of its first parameter: a class, an interface, a union,
  an intersection, or every event for `object`. The constructor rejects a listener
  that cannot take an event.
- A listener may subscribe to a class that is not final and to an interface.

### Removed

- **BC break:** `Dispatcher::subscribe()` and its unsubscribe closures. Pass the listeners to the constructor.

## [0.2.0] - 2026-05-07

### Changed

- **BC break** `Dispatcher::subscribe()` now requires the hook class to be `final`. Passing a non-final class throws `\ValueError`.
