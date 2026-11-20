# PHP functions that fail with a warning

Read before calling a PHP function that fails with a warning and `false`: files, streams, sockets, processes, HTTP.

## Rules

A bare call is never right: it warns and goes on. Such a function is called in one of three ways.

- `exceptionally()` of thesis/exceptionally where any warning is a failure and the path is cold: files, HTTP,
  a sequence of such calls, setup (`tools/monorepo`). The `\ErrorException` becomes an exception of the package.
  It throws on `false` too, so no `=== false` after it. Where `false` is an answer (`fgets()` at the end),
  the function returns `null` for it (`=== false ? null : $line`, not `?: null`, which takes `'0'` too).
- `error_clear_last()`, `@call()`, then `Internal\lastError($default)` on a hot path (`fread()`/`fwrite()`
  of `Stream` and `Socket`) and where the result says what failed (`false`, `0`), not the warning.
  The default is needed: `fread()` and `fwrite()` may fail without an error. Nothing may suspend between the clear
  and the read: the last error is global, after a suspension it may be of another fiber. `lastError()` is internal,
  so a package keeps a copy of its own (io, net).
- A bare `@` where a failure is an answer, not an error: `fclose()` while closing, the cleanup of a temporary file,
  a read of a file that may be missing.

## Traps

- PHP 8.6.0 starts every fiber with `error_reporting()` 0 when the ini setting has no value (no `php.ini`,
  as in the Docker images; php-src GH-25249): the errors of a fiber are then suppressed as if under `@`,
  `exceptionally()` throws only on `false`, a handler that checks `error_reporting() & $level` skips them.
  8.5 gives a fiber `E_ALL` there. A value in `php.ini`, `-d error_reporting=-1` or `ini_set('error_reporting', ...)`
  before the fiber starts fixes it. `error_reporting(E_ALL)` does not: it changes nothing when the value is the same.
  The tests are not affected: `vendor/bin/testo` sets `error_reporting()` to another value.
  The fix (php-src PR 25252) is not merged yet: check a new patch (`new Fiber(static fn() => error_reporting())`
  without an ini) and delete this once the lowest 8.6 we allow has it.

## Decisions

- No `exceptionally()` on a hot path: it costs a closure and two handler calls per call, and under `@`
  or `error_reporting` of the caller it throws only on `false`, without the reason, while `error_get_last()`
  records a suppressed error too.
