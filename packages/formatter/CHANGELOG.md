# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The package moved to the [Thesis monorepo](https://github.com/thesis-php/thesis).

### Changed

- **BC break:** Requires 64-bit PHP 8.5+.
- **BC break:** `format()` writes an empty array as `array{}`, not `list{}`, as PHPStan does.
- **BC break:** `format()` writes a float by `var_export()` and keeps it apart from an int: `1.0`, not `1`.
- **BC break:** `format()` quotes a key of an array or a property of `\stdClass` that is not a name: `'a b'`, `''`.
- **BC break:** `format()` writes a resource as `get_debug_type()` does: `resource (stream)`.
- **BC break:** `format()` writes a subclass of `\stdClass` as its class, not as `object{...}` with its properties.
- **BC break:** A string that is not a name of PHP is formatted as a string literal by `formatClass()`,
  `formatFunction()`, `formatParameter()` and `formatProperty()`.
- **BC break:** The parameter `$function` of `formatFunction()` and `formatParameter()` is renamed to `$fn`.
- `format()` stays short and finishes for any value: items stop once the result reaches 500 bytes, a string shows
  at most 100 characters and what is left of the 500 bytes, arrays and objects deeper than 3 levels are written
  as `{...}`. A cycle of objects or references used to exhaust the stack.
- `format()` writes a string as a valid PHP literal of the same bytes: a backslash before a quote or at the end
  is escaped, and a string with a byte that is not UTF-8, a control, a format (bidi, zero-width) or a space
  other than U+0020 is double-quoted with only those escaped (`"Приве\xD1"`, `"abc\u{202E}"`).
- `format()` formats a closed resource. It used to fail an assertion.
- `formatClass()` and `formatProperty()` take any string, `formatFunction()` and `formatParameter()` a string
  or a pair that is not callable: an error may be about a class or a method that does not exist.
  What is not a callable at all is formatted as a value.
- Code of a nested `eval()` is located by the line of the outermost one, and a file whose path has `(2)`
  is no longer taken for code of `eval()`.

### Removed

- **BC break:** `formatReflectedType()`. Use `(string) $type`: it keeps the parentheses of a DNF type,
  which `formatReflectedType()` lost.
- **BC break:** A parameter by position in `formatParameter()`. Pass its name.

## [0.1.0] - 2026-04-13

### Added

- `format()`, `formatClass()`, `formatFunction()`, `formatParameter()`, `formatProperty()`
  and their variants for reflection.
