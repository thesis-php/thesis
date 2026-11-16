# thesis/exceptionally

`exceptionally()` throws the PHP errors of a callable as `\ErrorException`. The API is described in `README.md`.

This file is for agents changing this package. It is not loaded while an agent works in another package,
so how to *use* this package belongs in `README.md` or in a skill under `skills/<skill-name>/SKILL.md`.

## Invariants that must not be broken

- The handler stack of PHP is global, a call of `exceptionally()` belongs to one fiber. So the levels live
  per fiber (`$levelsOfFibers`, keyed by `\Fiber::getCurrent() ?? $main`), and every `Internal\ErrorHandler` reads
  the levels of the fiber the error comes from: all of them behave the same, the one of a finished call too.
- While an `ErrorHandler` is on top, a call sets the same object again. A new one is made only above a handler
  of other code (or none), which becomes its `$previous`. So the previous handler of ours is never ours, an error
  passed on makes one hop for any number of suspended calls (1 ms through 1000 handlers otherwise,
  `passesOnPastTheHandlersOfOtherCalls`), and the copies of one object lie next to each other.
- `finally` never restores blindly: the top may be a handler of other code set meanwhile, or nothing while a handler
  runs (a restore there makes PHP drop the running one). The call adds itself to `$finishedCalls` of its handler,
  then takes the top off while it is ours and has finished calls. Any copy stands for the call's own, and a handler
  above one of other code has a number of its own, so a call below never takes off the handler of a call above
  (`throwsAboveAHandlerOfOtherCodeAfterAnotherFiberFinishes`).
- Without other code there are as many handlers of ours as running calls (`setsNoMoreHandlersThanCallsThatRun`).
  Handlers of finished calls stay only below a handler of other code, at most as many as calls ran when it was set,
  and the first call that finishes after it is restored takes them off. They cannot be taken off earlier:
  only the top is reachable, and setting the handler above again would lose its levels.
- The loop reads `$finishedCalls` last and calls `restore_error_handler()` at once. Nothing that releases a value
  may come between (a method call releases `$this`): the cycle collector starts at any release, destroys
  an abandoned fiber, and its `finally` takes the same copies off (`keepsTheHandlerBelowWhenTheCollectorRunsInside`).
- An error that is not thrown (another fiber, another level, suppressed) goes to the previous handler,
  not `return false`: `false` goes straight to the standard handler of PHP and skips the one of the application.
- Suppression is `error_reporting() & $level`. `error_reporting()` is saved per fiber by PHP (`@` in a suspended
  fiber does not affect others, `throwsWhileAnotherFiberIsSuspendedInSuppression`), and it also respects
  the `error_reporting` ini setting.

## Decisions

- The function is `Thesis\exceptionally()`, as in the old `thesis/exceptionally`, not `Thesis\Exceptionally\...`:
  the name of the package repeated in the namespace reads as a stutter. The manifest sets `namespace: 'Thesis\\'`.
- No dependency on `thesis/async`: the package is useful without an event loop. The key of the fiber is inlined
  as `\Fiber::getCurrent() ?? $main` instead of `Internal\fiberKey()` of async: the function and its handler use it.
- A fiber started inside the function does not inherit the levels: its errors are its own.
- The previous handler is called for every level, although it may have been set for some only:
  `set_error_handler()` does not return its levels. Accepted: the alternative is to track every handler.
- `false` is a failure, checked after the call: the function is for the functions of PHP that fail
  with a warning and `false`, and `false` comes back without an exception when the error was suppressed,
  at another level, swallowed by another handler, or there was none (`curl_exec()`, `preg_match()`).
  The type is `callable(): T` returning `(T is false ? never : T)`: PHPStan distributes it over a union,
  so `bool` becomes `true` and `string|bool` becomes `string|true`, which `callable(): (T|false)` returning `T`
  does not (`types/ExceptionallyTypes.php`). The exception has `E_WARNING`: it stands for the warning.
  Accepted: `false` as an answer throws too (`fgets()` at the end, `unserialize()`), the function returns
  something else instead (README).
  Rejected: no check (the caller repeats `=== false` after every call, and forgets it); narrowing by
  the PHPStan extension for a list of functions (suppression, levels and other handlers decide at runtime,
  the type would be narrower than proven); a second function with the check, or wrappers per function
  like `thecodingmachine/safe` (one more choice, and `false` as an answer is not what this function is for);
  rethrowing the last error that was not thrown as the reason (a record per call and per error in the handler,
  and a guess: a deprecation or a notice from elsewhere in the function would pass for the reason).
  Rejected: `exceptional()`, a wrapper that makes a callable exceptional: `static fn() => exceptionally(...)` is
  as short, and the old one was broken.
- One `set_error_handler()` per call, even if ours is on top: the object is shared, the set is not skipped.
  Rejected: a global counter of calls that sets the handler at 0→1 and restores it at 1→0, or skipping the set
  when ours is on top: a handler that the function sets and keeps (a framework for the time of its `run()`)
  would then be above ours for nested calls and could swallow their errors.
- The handler is an invokable `Internal\ErrorHandler`, not a closure with a `\WeakMap` of numbers: `instanceof`
  tells ours from other code, the number is a property, and a call is 40 ns cheaper.
- Rejected: a blind `restore_error_handler()` in `finally`. It keeps the numbers of sets and restores equal,
  but takes off a handler other code set meanwhile (a late Sentry, a handler set inside `$fn`), the handler
  of a nested call above a handler of other code, and the handler that runs when a fiber is destroyed inside it.
- Rejected: a handler per call marked as finished and taken off once on top. A handler below the one of a running
  call stays until every call above has finished: two loops of calls that overlap never let it, 1.3 KB per call.
  And a mark is not used up by taking the handler off: when the collector ran between the check and the restore,
  two `finally` took off the same handler and the next restore took off the one of the application.
- Rejected, may be worth it later: paying the restore back. Other code sets F, a call starts above it and
  is suspended, the other code restores, meaning F (a test framework with a handler per test and a fiber left
  suspended after the test). PHP takes off the top, ours: `[P, F, ours]` becomes `[P, F]`. The errors of the call
  are not thrown anymore, and when it finishes, the top is not ours, so F stays for good though its owner
  thinks it is gone. Paying back would be: after the loop in `finally`, if the handler of the call still has
  finished calls and the top is exactly its `$previous` (not null: null is also what a running handler shows),
  no copy is left above `$previous`, so a restore of other code took ours in its place, and the call takes
  `$previous` off for it. The stack is then as it would be without `exceptionally()`: in the stress test that was
  so in 259 of 373 scenarios with such a restore instead of 141. Rejected because it takes off a handler we
  did not set, on a guess that is wrong once F was restored properly and the same object set again, it does
  nothing when something else was set above F meanwhile, and the call is without a handler in between anyway.
  "Takes off only its own" has no exceptions now. To add it: `$previous` becomes readable from the function,
  about 8 lines in `finally`, a test of the scenario above and one of the same object set again.
- Rejected: a finished handler that takes itself off when it gets an error (`restore_error_handler()` inside
  a handler does that): it cannot tell a call by PHP from a call by the handler above that passes on,
  and a restore then drops that one.
- The parameter is `$fn`, as the root rule of naming callables says, not `$function` of 0.3: the release
  breaks the API anyway.
- Rejected: setting one handler once and never restoring it: code that sets a handler for a while and restores it
  (a test framework per test) would remove ours and leave its own forever.
- Rejected: `@` with `error_get_last()`, also for the reason of a `false`: it is empty when the handler
  of the application returned `true`, and the last error is global, after a suspension it may be of another fiber,
  and it keeps only the last one.

## Tests

- `types/ExceptionallyTypes.php` holds `assertType()` fixtures of the result type, never executed:
  `make phpstan` checks them. A change of the PHPDoc of `exceptionally()` keeps them passing or changes them.

- A deprecation is triggered by `deprecate()` of `tests/functions.php`, never by `trigger_error()` in a test:
  the `error_suppression` rule of PHP CS Fixer puts `@` before every `trigger_error()` of `E_USER_DEPRECATED`,
  and a suppressed deprecation passes the tests whatever `exceptionally()` does. The manifest excludes the file.
- Testo creates one instance of a test class for all its tests: `#[BeforeTest]` resets `$passedOn`.
- `errorHandlers()` of `tests/functions.php` returns the whole stack: `get_error_handler()` shows only the top.
- A test that restores its own handler above a finished call makes one more call before it asserts the stack:
  the handler of the finished call is on top until then. A failed test leaves its handlers behind,
  so a test of the stack compares with what was there before, not with the top alone.
- The stress test of random scenarios and the sweep of the collector that the design was checked with are not
  in the suite: they need the stack dumped after every step and run for minutes. Changing how handlers are set
  or taken off means writing such a check again, the tests here are single scenarios.
