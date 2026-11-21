# thesis/exceptionally

`exceptionally()` throws the PHP errors of a callable as `\ErrorException`. The API is in `README.md`.

## Invariants that must not be broken

- The handler stack of PHP is global, a call belongs to one fiber. So the levels live per fiber (`$levelsOfFibers`,
  keyed by `\Fiber::getCurrent() ?? $main`), and every `Internal\ErrorHandler`, of a finished call too, reads
  the levels of the fiber the error comes from.
- The map holds one `int` per fiber, the levels of its innermost running call. A call keeps the levels
  of the outer one in a local variable and puts them back in `finally`: the nested calls of a fiber lie
  on its stack anyway. `null` there means no outer call, the entry is removed.
- If an `ErrorHandler` is already on top, the call installs the same object again. The call creates a new
  `ErrorHandler` only if the top handler belongs to other code or no handler is active, and the new handler
  stores that handler in `$previous`. So `$previous` is never ours, and a forwarded error takes one hop
  regardless of how many calls are suspended. Walking 1000 handlers would take 1 ms
  (`passesOnPastTheHandlersOfOtherCalls`). Copies of the same object sit next to each other.
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
  `false` sends the error straight to PHP's standard handler and skips the application handler.
- Suppression is `error_reporting() & $level`: PHP keeps `error_reporting()` per fiber (`@` in a suspended fiber
  does not affect others, `throwsWhileAnotherFiberIsSuspendedInSuppression`), and it respects the ini setting.
  Trap: PHP 8.6.0 starts a fiber with 0 when the ini setting has no value, nothing is thrown there (`agents/errors.md`).
- The package runs on PHP 8.2.2+, the rest of the monorepo on 8.6. The code, tests included, has no syntax or API
  newer than 8.2 but `get_error_handler()`: no `new X()->call()` (a variable keeps the fixer from making it).
  PHPStan and Rector take the PHP of the monorepo and miss it, `make test-alone P=exceptionally` on 8.2 to 8.5
  catches it.
- Below 8.5 `get_error_handler()` is of `symfony/polyfill-php85`: `set_error_handler(null)`, then
  `restore_error_handler()`. Nothing is released between the two, so the collector never starts while the top
  is taken off, and while a handler runs the pair puts the same nothing back. A polyfill that does more
  between them breaks the loop of `finally`.

## Decisions

- The function is `Thesis\exceptionally()`, as in the old `thesis/exceptionally`: `Thesis\Exceptionally\...`
  stutters. The manifest sets `rootEntry: true`, the handler and the helpers of the tests are
  in `Thesis\Exceptionally\`.
- PHP 8.2.2, not the 8.6 of the monorepo: the package is useful without the rest, 0.3 ran on 8.3, and the code
  needs nothing newer but `get_error_handler()`. 8.2 is the lowest PHP of Testo: the code runs on 8.1,
  where nothing would test it. 8.2.2: before it the collector does not see the stack of a fiber
  (php-src GH-9735), an abandoned fiber and its handler stay until the exit, and the two tests of it fail.
- `symfony/polyfill-php85` for `get_error_handler()`, not a function of our own: a global function defined
  by a library collides with the polyfill of an application, and a wrapper with `function_exists()` costs
  a call on 8.5+ too. Accepted: below 8.5 a call sets and restores a handler twice more.
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
  is just as short, and the old `exceptional()` was broken.
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
- Rejected: one handler set once and never restored: code that sets a handler for a while and restores it
  (a test framework per test) would remove ours and leave its own forever.
- A call costs about 400 ns on PHP 8.5+ and 650 to 800 ns below, with the polyfill (no opcache),
  a `file_get_contents()` of a small file 3 to 4 µs.
  Rejected: a list of levels per fiber in the map instead of the local variable, 100 ns more for a call.
  Rejected: a second function for a callable that does not suspend (a handler per call, a blind restore),
  190 ns: when the callable suspends after all, errors of other fibers are thrown and the restore takes off
  a handler of other code, silently.
  Rejected, may be worth it on a warmer path: no state per fiber, the handler walks `debug_backtrace()`
  to the nearest frame of `exceptionally()` for its `$levels` and stops at a frame of `\Fiber` (below are
  the frames of the code that resumed the fiber). 250 ns for a call, but 1 µs more for every reported error
  at a depth of 5 frames and 5 µs at 50 while any call runs, and it depends on the shape of the backtrace.
- Rejected: `@` with `error_get_last()`, also to explain a `false` return. PHP records nothing when the
  application handler returns `true`. After a suspension, the recorded error may come from another fiber.
  PHP keeps only the latest error.

## Tests

- `types/ExceptionallyTypes.php` holds `assertType()` fixtures, never executed: `make phpstan` checks them.
- A deprecation is triggered by `deprecate()` of `tests/Exceptionally/helpers.php`, never by `trigger_error()`
  in a test: the `error_suppression` rule of PHP CS Fixer puts `@` before it, and a suppressed deprecation passes
  the tests whatever `exceptionally()` does. The manifest excludes the file from the fixer.
- Testo creates one instance of a test class for all its tests: `#[BeforeTest]` resets `$passedOn`.
- `errorHandlers()` of `tests/Exceptionally/helpers.php` returns the whole stack: `get_error_handler()` shows
  only the top.
- A test that restores its own handler above a finished call makes one more call before asserting the stack:
  until then the handler of the finished call is on top. A failed test leaves its handlers behind,
  so a test of the stack compares with what was there before, not with the top alone.
- The stress test of random scenarios and the sweep of the collector that checked the design are not in the suite:
  they dump the stack after every step and run for minutes. A change in how handlers are set or taken off needs
  such a check written again.
- The last one compared the polyfill with `get_error_handler()` of PHP: 3000 random scenarios of 300 steps
  (fibers with nested calls, abandoned, with a cycle and without, handlers of other code set and restored,
  one that calls `exceptionally()` and one that destroys a fiber) logged every error and the stack after
  every step, and the logs of PHP 8.2 to 8.6 were equal. The collector starts inside a call at 1 to 5 free slots
  of the root buffer on each of them, `keepsTheHandlerBelowWhenTheCollectorRunsInside` sweeps 24.
  Traps of such a comparison: `E_ALL` has `E_STRICT` below 8.4, so the levels of a log differ. The output
  goes through stdout of the container: files written by several containers to a mounted directory had holes.
