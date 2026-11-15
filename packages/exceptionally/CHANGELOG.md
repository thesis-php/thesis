# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The package moved to the [Thesis monorepo](https://github.com/thesis-php/thesis) and continues
the version series of it.

### Changed

- **BC break:** Requires 64-bit PHP 8.5+.
- **BC break:** The parameter `$function` of `exceptionally()` is renamed to `$fn`.
- **BC break:** `exceptionally()` takes `callable(): T` and returns `T`. It used to take `callable(): (T|false)`
  and promise a `T` without `false`, which was wrong for functions that return `false` without an error.
- An error that is not converted (another level, suppressed with `@`) goes to the previous error handler,
  so the handler of the application still sees it. It used to bypass it.
- Errors suppressed by the `error_reporting` ini setting are not converted either.
- The callable may suspend a fiber: while it is suspended, errors of other fibers are not converted,
  and fibers that finish in any order leave the error handlers as they were.

### Removed

- **BC break:** `exceptional()`. Use `static fn() => exceptionally($function)` instead.

## [0.3.0] - 2026-03-30

### Changed

- **BC break:** `E_DEPRECATED` and `E_USER_DEPRECATED` are no longer converted by default. Pass `E_ALL` explicitly to include them.
- **BC break:** Errors suppressed with `@` are no longer converted to exceptions.
