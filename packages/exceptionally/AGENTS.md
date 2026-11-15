# thesis/exceptionally

`exceptionally()` throws the PHP errors of a callable as `\ErrorException`. The API is described in `README.md`.

This file is for agents changing this package. It is not loaded while an agent works in another package,
so how to *use* this package belongs in `README.md` or in a skill under `skills/<skill-name>/SKILL.md`.

## Invariants that must not be broken

- The handler stack of PHP is global, a call of `exceptionally()` belongs to one fiber. So the levels live
  per fiber (`$levelsOfFibers`, keyed by `\Fiber::getCurrent() ?? $main`), and every handler that `exceptionally()`
  sets reads the levels of the fiber the error comes from. Fibers suspended inside `exceptionally()` finish in any order
  and `restore_error_handler()` removes the handler of another call: harmless, since all of them behave the same.
  The number of `set_error_handler()` and `restore_error_handler()` calls must stay equal, `finally` keeps it.
- An error that is not thrown (another fiber, another level, suppressed) goes to the previous handler,
  not `return false`: `false` goes straight to the standard handler of PHP and skips the one of the application.
- Suppression is `error_reporting() & $level`. `error_reporting()` is saved per fiber by PHP (`@` in a suspended
  fiber does not affect others, `throwsWhileAnotherFiberIsSuspendedInSuppression`), and it also respects
  the `error_reporting` ini setting.

## Decisions

- The function is `Thesis\exceptionally()`, as in the old `thesis/exceptionally`, not `Thesis\Exceptionally\...`:
  the name of the package repeated in the namespace reads as a stutter. The manifest sets `namespace: 'Thesis\\'`.
- No dependency on `thesis/async`: the package is useful without an event loop. The key of the fiber is inlined
  as `\Fiber::getCurrent() ?? $main` instead of `Internal\fiberKey()` of async: one function uses it.
- A fiber started inside the function does not inherit the levels: its errors are its own.
- The previous handler is called for every level, although it may have been set for some only:
  `set_error_handler()` does not return its levels. Accepted: the alternative is to track every handler.
- `callable(): T` returns `T`. Rejected: `callable(): (T|false)` returning `T` of the old `thesis/exceptionally`,
  it lies for functions that return `false` without an error (`strpos()`, `array_search()`).
  Rejected: `exceptional()`, a wrapper that makes a callable exceptional: `static fn() => exceptionally(...)` is
  as short, and the old one was broken.
- Rejected: setting one handler once and never restoring it: code that sets a handler for a while and restores it
  (a test framework per test) would remove ours and leave its own forever.
- Rejected: `@` with `error_get_last()`: the last error is global, after a suspension it may be of another fiber,
  and it keeps only the last one.

## Tests

- A deprecation is triggered by `deprecate()` of `tests/functions.php`, never by `trigger_error()` in a test:
  the `error_suppression` rule of PHP CS Fixer puts `@` before every `trigger_error()` of `E_USER_DEPRECATED`,
  and a suppressed deprecation passes the tests whatever `exceptionally()` does. The manifest excludes the file.
- Testo creates one instance of a test class for all its tests: `#[BeforeTest]` resets `$passedOn`.
