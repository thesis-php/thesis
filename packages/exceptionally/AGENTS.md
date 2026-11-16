# thesis/exceptionally

`exceptionally()` throws the PHP errors of a callable as `\ErrorException`. The API is in `README.md`.

## Invariants

- The handler stack of PHP is global, a call belongs to one fiber. So the levels live per fiber (`$levelsOfFibers`,
  keyed by `\Fiber::getCurrent() ?? $main`), and every `Internal\ErrorHandler`, of a finished call too, reads
  the levels of the fiber the error comes from.
- While an `ErrorHandler` is on top, a call sets the same object again. A new one is made only above a handler
  of other code (or none), which becomes its `$previous`. So `$previous` is never ours, a passed on error makes
  one hop for any number of suspended calls (1 ms through 1000 handlers otherwise,
  `passesOnPastTheHandlersOfOtherCalls`), and the copies of one object lie next to each other.
- `finally` never restores blindly: the top may be a handler of other code, or nothing while a handler runs
  (a restore there makes PHP drop the running one). The call increments `$finishedCalls` of its handler,
  then takes the top off while it is ours and has finished calls. Any copy stands for the call's own, and a handler
  above one of other code has a number of its own, so a call never takes off the handler of a call above
  (`throwsAboveAHandlerOfOtherCodeAfterAnotherFiberFinishes`).
- Without other code there are as many handlers of ours as running calls (`setsNoMoreHandlersThanCallsThatRun`).
  Handlers of finished calls stay only below a handler of other code, at most as many as calls ran when it was set,
  until the first call that finishes after it is restored. Earlier is impossible: only the top is reachable,
  and setting the handler above again would lose its levels.
- The loop reads `$finishedCalls` last and calls `restore_error_handler()` at once. Nothing that releases a value
  may come between (a method call releases `$this`): the cycle collector may start at any release, destroy
  an abandoned fiber, and its `finally` takes the same copies off (`keepsTheHandlerBelowWhenTheCollectorRunsInside`).
- An error that is not thrown (another fiber, another level, suppressed) goes to `$previous`, not `return false`:
  `false` goes straight to the standard handler of PHP and skips the one of the application.
- Suppression is `error_reporting() & $level`: PHP keeps `error_reporting()` per fiber (`@` in a suspended fiber
  does not affect others, `throwsWhileAnotherFiberIsSuspendedInSuppression`), and it respects the ini setting.

## Decisions

- The function is `Thesis\exceptionally()`, as in the old `thesis/exceptionally`: `Thesis\Exceptionally\...`
  stutters. The manifest sets `namespace: 'Thesis\\'`.
- No dependency on `thesis/async`: the package is useful without an event loop. So the key of the fiber
  is inlined (`\Fiber::getCurrent() ?? $main`) instead of `Internal\fiberKey()` of async.
- A fiber started inside the function does not inherit the levels: its errors are its own.
- The previous handler gets every level, although it may have been set for some only: `set_error_handler()`
  does not return its levels. Accepted: the alternative is tracking every handler.
- `false` is a failure, checked after the call: it comes back without an error when the error was suppressed,
  at another level, swallowed by another handler, or there was none (`curl_exec()`, `preg_match()`).
  The return type `(T is false ? never : T)` of `callable(): T` is distributed by PHPStan over a union
  (`bool` to `true`, `string|bool` to `string|true`), `callable(): (T|false)` returning `T` is not
  (`types/ExceptionallyTypes.php`). The exception has `E_WARNING`: it stands for the warning.
  Accepted: `false` as an answer throws too (`fgets()` at the end), the caller returns something else (README).
  Rejected: no check (the caller repeats `=== false` and forgets it); narrowing by the PHPStan extension
  for a list of functions (suppression, levels and handlers decide at runtime); a second function with the check,
  or wrappers per function like `thecodingmachine/safe` (one more choice for no gain); rethrowing the last
  error that was not thrown (a record per error, and a guess: an unrelated notice would pass for the reason).
- Rejected: `exceptional()`, a wrapper that makes a callable exceptional: `static fn() => exceptionally(...)`
  is as short, and the old one was broken.
- One `set_error_handler()` per call, even if ours is on top. Rejected: a global counter that sets the handler
  at 0→1 and restores it at 1→0, or skipping the set when ours is on top: a handler that the function sets
  and keeps (a framework during its `run()`) would be above ours for nested calls and could swallow their errors.
- The handler is an invokable `Internal\ErrorHandler`, not a closure with a `\WeakMap` of numbers: `instanceof`
  tells ours from other code, the number is a property, and a call is 40 ns cheaper.
- Rejected: a blind `restore_error_handler()` in `finally`. It takes off a handler that other code set meanwhile
  (a late Sentry, one set inside `$fn`), the handler of a nested call above a handler of other code,
  and the handler that runs when a fiber is destroyed inside it.
- Rejected: a handler per call, marked as finished and taken off once on top. A handler below a running call
  stays until every call above has finished: two overlapping loops of calls never let it, 1.3 KB per call.
  And a mark is not used up by the restore: when the collector ran between the check and the restore,
  two `finally` took off the same handler, and the next one took off the handler of the application.
- Rejected, may be worth it later: paying the restore back. Other code sets F, a call starts above it and
  is suspended, the other code restores F (a test framework per test, a fiber left suspended). PHP takes off ours:
  `[P, F, ours]` becomes `[P, F]`, the errors of the call are not thrown, and F stays for good. Paying back:
  after the loop in `finally`, if the handler of the call still has finished calls and the top is exactly
  its `$previous` (not null: a running handler shows null too), take `$previous` off. In the stress test
  the stack then matched the one without `exceptionally()` in 259 of 373 such scenarios instead of 141.
  Rejected: it takes off a handler we did not set on a guess that is wrong once F was restored properly
  and set again, it does nothing when something else was set above F, and the call has no handler in between
  anyway. To add it: `$previous` readable from the function, about 8 lines in `finally`, a test of the scenario
  and one of F set again.
- Rejected: a finished handler that takes itself off when it gets an error: it cannot tell a call by PHP
  from a call by the handler above that passes on, and its restore drops that one.
- The parameters are `$fn` and `$levels`, not `$function` and `$errorLevels` of 0.3: the release breaks the API anyway.
- Rejected: one handler set once and never restored: code that sets a handler for a while and restores it
  (a test framework per test) would remove ours and leave its own forever.
- Rejected: `@` with `error_get_last()`, also for the reason of a `false`: it is empty when the handler
  of the application returned `true`, after a suspension it may be of another fiber, and it keeps only the last one.

## Tests

- `types/ExceptionallyTypes.php` holds `assertType()` fixtures, never executed: `make phpstan` checks them.
- A deprecation is triggered by `deprecate()` of `tests/helpers.php`, never by `trigger_error()` in a test:
  the `error_suppression` rule of PHP CS Fixer puts `@` before it, and a suppressed deprecation passes the tests
  whatever `exceptionally()` does. The manifest excludes the file from the fixer.
- Testo creates one instance of a test class for all its tests: `#[BeforeTest]` resets `$passedOn`.
- `errorHandlers()` of `tests/helpers.php` returns the whole stack: `get_error_handler()` shows only the top.
- A test that restores its own handler above a finished call makes one more call before asserting the stack:
  until then the handler of the finished call is on top. A failed test leaves its handlers behind,
  so a test of the stack compares with what was there before, not with the top alone.
- The stress test of random scenarios and the sweep of the collector that checked the design are not in the suite:
  they dump the stack after every step and run for minutes. A change in how handlers are set or taken off needs
  such a check written again.
