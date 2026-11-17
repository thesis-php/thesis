# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- A listener gets the dispatcher as its second argument, to dispatch events of its own:
  `static function (OrderPlaced $event, Dispatcher $dispatcher)`.
- `Dispatcher\Subscriber`: an object with several listeners, given to the constructor among them:
  `new Dispatcher([new Mailer(), $listener])`.

### Changed

- **BC break:** A second parameter of a listener that does not take `Thesis\Dispatcher` gets it anyway
  and fails. Declare it after a parameter of the dispatcher.
- Requires 64-bit PHP 8.6+.

### Removed

- **BC break:** `#[Dispatcher\Listener]` and `Dispatcher\listenersOf()`. Implement `Dispatcher\Subscriber`:
  its `listeners()` gives them, `yield $this->onRegistered(...)`.

## [0.3.0] - 2026-11-17

The package moved to the [Thesis monorepo](https://github.com/thesis-php/thesis) and was written anew.

### Added

- `Dispatcher::listens()` tells whether any listener gets the events of a class.
- `Dispatcher\listenersOf()` gives the methods of an object marked `#[Dispatcher\Listener]` as listeners.

### Changed

- **BC break:** Requires 64-bit PHP 8.5+.
- **BC break:** The listeners are given to the constructor as an iterable: `new Dispatcher($listeners)`.
- **BC break:** A listener gets the events of the type of its first parameter: a class, an interface, a union,
  an intersection, or every event (`object`, `mixed`, no type). The constructor rejects one that cannot take it.
- A listener may take the events of a class that is not final and of an interface.

### Removed

- **BC break:** `Dispatcher::subscribe()` and its unsubscribe closures. Pass the listeners to the constructor.
