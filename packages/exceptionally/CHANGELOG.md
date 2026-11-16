# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The package moved to the [Thesis monorepo](https://github.com/thesis-php/thesis).

### Changed

- **BC break:** Requires 64-bit PHP 8.5+.
- **BC break:** The parameters of `exceptionally()` are renamed: `$function` to `$fn`, `$errorLevels` to `$levels`.
- **BC break:** `exceptionally()` throws an `\ErrorException` when the callable returns `false` without an error.
- Levels turned off by `error_reporting` are not thrown.
- The callable may suspend a fiber: errors of other fibers are not thrown meanwhile.

### Fixed

- An error that is not thrown goes to the previous error handler instead of bypassing it.
- A handler that other code sets while the callable runs is no longer taken off.

## [0.3.0] - 2026-03-30

### Changed

- **BC break:** `E_DEPRECATED` and `E_USER_DEPRECATED` are no longer converted by default. Pass `E_ALL` explicitly to include them.
- **BC break:** Errors suppressed with `@` are no longer converted to exceptions.
