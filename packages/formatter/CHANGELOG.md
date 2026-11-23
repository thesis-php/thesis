# Changelog

This changelog records notable changes to the package.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.1] - 2026-11-23

### Changed

- The package now requires PHP 8.4+ instead of 64-bit PHP 8.5+.

## [0.2.0] - 2026-11-17

The package moved to the [Thesis monorepo](https://github.com/thesis-php/thesis).

### Changed

- **BC break:** Requires 64-bit PHP 8.5+.
- **BC break:** `format()` is renamed to `formatValue()`.
- **BC break:** `formatValue()` writes an empty array as `array{}`, as PHPStan does.
- **BC break:** `formatValue()` keeps a float apart from an int: `1.0`, not `1`.
- **BC break:** `formatValue()` quotes a key or a property of `\stdClass` that is not a name: `'a b'`.
- **BC break:** `formatValue()` writes a resource as `get_debug_type()` does: `resource (stream)`.
- **BC break:** `formatValue()` writes a subclass of `\stdClass` as its class.
- **BC break:** `formatClass()`, `formatFunction()`, `formatParameter()` and `formatProperty()` write a string
  that is not a name of PHP as a string literal.
- **BC break:** The parameter `$function` of `formatFunction()` and `formatParameter()` is renamed to `$fn`.
- `formatValue()` cuts long strings, large arrays and deep nesting, so its result stays short.
- `formatValue()` writes a string as a valid PHP literal of the same bytes.
- `formatClass()` and `formatProperty()` take any string, `formatFunction()` and `formatParameter()`
  also what is not callable.

### Removed

- **BC break:** `formatReflectedType()`. Use `(string) $type`.
- **BC break:** A parameter by position in `formatParameter()`. Pass its name.

### Fixed

- `formatValue()` finishes on a cycle of objects or references instead of exhausting the stack.
- `formatValue()` formats a closed resource instead of failing an assertion.
- Code of a nested `eval()` is located by the line of the outermost one.
- A file whose path has `(2)` is no longer taken for code of `eval()`.

## [0.1.0] - 2026-04-13

### Added

- `format()`, `formatClass()`, `formatFunction()`, `formatParameter()`, `formatProperty()`
  and their variants for reflection.
