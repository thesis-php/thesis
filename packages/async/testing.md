# Testing the async runtime

- Every test runs on virtual time (`VirtualTimePlugin` of thesis/testo-plugin in `testoPlugins` of the manifest; no
  attribute is needed): delays are whole seconds (`delay(sec(1))`), a wait that only a cancellation ends is `never()`
  from `tests/helpers.php`, durations are asserted exactly (`Assert::same(secondsSince($start), 20.0)`,
  `Assert::equals(remaining(), sec(990))`), and busy code is `burnCpu()`, not a loop on the real clock. Keep a real
  `delay()` where its timer matters: a test of `delay()` itself, or a timer that keeps the loop alive. Real time is only
  for what virtual time cannot model.
- A limiter, a pacer or a clock is tested beyond one caller on time: with several callers sharing it, with a blocked
  loop (`burnCpu()` while a caller sleeps), with a wake-up late by less than its interval (what a real timer
  does on every call, invisible on virtual time otherwise), with the longest `Duration` or an extreme rate, and, if it
  keeps a callback in the loop across calls, after that callback is dropped with its loop (`virtualTime()` drops
  what is left), and across a nested loop. None of these was tested, and each hid a defect.
- A stream or a signal on virtual time is tested with another timer pending (a `timeout()` around it):
  without one, the clock has nowhere to jump, and a jump past a ready stream is never seen.
- A callback of the loop itself (a timer of a library, not a fiber) is `later()` of `tests/helpers.php`.
- An outer cancellation is tested against everything the group may have cancelled itself before it:
  daemons, `Task::cancel()`, `cancelTasks()`, the losers of `race()` and `some()`.
- A behaviour that depends on combinations is also checked on generated programs: `RandomizedVirtualTimeTest`
  (random pipes, relays, raw timers and daemons: when the clock may jump) and `CancelledFirstTest` (every
  combination against a model of who cancelled first). A new way to wait on virtual time or to cancel a task
  is added to them. A combination that cannot behave differently from another one is left out (a cleanup
  of a task that ends before anything cancels it): `CancelledFirstTest` once took a third of the suite.
  Rejected: running the cases concurrently in one `virtualTime()`, it was five times slower (thousands of live fibers).
  Rejected: a random stress of groups, channels and semaphores with general checks.
  It never found a defect, not even the ones a review found by reading.
