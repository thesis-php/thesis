# PHP functions that fail with a warning

Read before calling a PHP function that fails with a warning and `false`: files, streams, sockets, processes, HTTP.

## Rules

A bare call is never right: it warns and goes on. Such a function is called in one of three ways.

- `exceptionally()` of thesis/exceptionally where any warning is a failure and the path is cold: files, HTTP,
  a sequence of such calls, setup (`tools/monorepo`). The `\ErrorException` becomes an exception of the package.
- `error_clear_last()`, `@call()`, then `Internal\lastError($default)` on a hot path (`fread()`/`fwrite()`
  of `Stream` and `Socket`) and where the result says what failed (`false`, `0`), not the warning.
  The default is needed: `fread()` and `fwrite()` may fail without an error. Nothing may suspend between the clear
  and the read: the last error is global, after a suspension it may be of another fiber. `lastError()` is internal,
  so a package keeps a copy of its own (io, net).
- A bare `@` where a failure is an answer, not an error: `fclose()` while closing, the cleanup of a temporary file,
  a read of a file that may be missing.

## Decisions

- No `exceptionally()` on a hot path: it costs a closure and two handler calls per call, and under `@`
  or `error_reporting` of the caller it throws nothing, while `error_get_last()` records a suppressed error too.
