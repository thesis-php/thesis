# Clocks and pacing

- `Pacer` is separate from `StreamWatcher`: fairness of a fast path has nothing to do with streams
  (a hot channel needs it too). It counts calls per tick: the count starts anew when `Loop::$tick` differs from
  the one it counted, so it registers nothing. Past the limit it calls `checkpoint()` *before* the operation,
  so a `Cancelled` from it means the operation did not happen. After that yield it counts the new tick, where other
  callers of a shared pacer may have counted already (a reset let N fibers pass N × `$perTick`). The numbers of ticks
  are unique among all the loops: a pacer shared by a nested loop (`stdout()` of thesis/io in `virtualTime()`) would
  otherwise count the calls of another loop's tick with the same number.
  Calls under the limit do not check cancellation: a fast path stays a counter increment.
- The deadline is resolved like the cancellation mark: a `Scope` stores the absolute deadline (`Scope::now()`, null if
  none), the minimum of its own and the parent's, and a shield drops the parent's. `remaining()` reads one field and the
  clock; `suspend()` does not touch the deadline: only the `timeout()` timer enforces it.
- `Instant`, deadlines and rate limiters read `Internal\Scope::now()`. The event loop reads its own
  `Internal\Loop::now()` to schedule timers. Both use `hrtime(true)` when running on real time.
  Within `virtualTime()`, `Scope::now()` reads `Internal\Scope::$virtualClock`, which
  `virtualTime()` binds to the clock of its loop. The binding is on `Scope`, not on `Internal\VirtualClock`,
  so that an application that never calls `virtualTime()` never loads the class. The clock is not read
  in `suspend()`. The virtual clock starts at `Scope::now()` rounded up to a whole second, not at zero:
  a time captured before `virtualTime()` stays in the past.
- `virtualTime()` runs a loop of its own on a `VirtualClock` and releases it at the end (`Loop::release()`):
  what is left in it is dropped. In a fiber (a task, a test of Testo) it is a nested loop that blocks the outer one
  until it ends; the outer clock is restored after its last tick.
- A rate limiter keeps absolute times and sleeps once per call: a timer of the loop never fires early,
  so a second sleep after the wake-up would never be needed.
  It reads the clock after the wake-up only to account for a late one: a blocked loop must not let `$burst + 1` calls
  through. The sliding window stores the later of the planned and the actual start; the token bucket restarts its
  counting from the wake-up only if the bucket has been full for a whole token interval by then. A smaller lateness
  keeps the schedule: a real timer is late on every call, and restarting from each wake-up ran a `burst: 1` limiter
  a quarter below its rate.
- A duration is `Time\Duration` (`agents/php-api.md`), `remaining()` returns one. A point in time is `Instant`,
  not a `Duration` (as `thesis/time` decided) and not a float: the difference of two is a `Duration`.
  Its shape follows the `Instant` sketched in the future scope of the Duration RFC: `add(Duration)`, the difference
  is a signed `Duration`, no adding of two instants. A native one would not follow `virtualTime()`, so ours stays
  even then; it may wrap the native one. A rate (`$perSecond`) is not a duration and stays a float.
- Inside, time is `int` nanoseconds (`agents/php-api.md`): `Internal\Scope::now()` is `hrtime(true)` or the virtual clock,
  and so are the deadlines, `Instant`, the rate limiters and the timers of the loop, with no float boundary:
  the timeout of `Io\Poll` is a `Duration`. An int overflow turns into a float and a
  `TypeError`, so every sum with a `Duration` or a rate from outside is guarded. A deadline, a timer,
  the virtual clock and the times of the rate limiters saturate at `PHP_INT_MAX` (`Scope::after()`,
  `Loop::delay()`, `VirtualClock::advance()`, the window, `tokenAt()`): a timeout of centuries is still
  a deadline, only one that never comes. `Scope::remaining()` and the sleep of a rate limiter are capped at
  `Scope::LONGEST_DURATION`, a little below `PHP_INT_MAX`, so `ns()` of them never throws.
  `Instant` is a value, so it does not saturate: `add()` and `since()` throw
  `Time\TimeException` beyond int, as `Duration` does beyond its range.
  Rejected: float seconds (they lose nanoseconds after about 100 days of uptime and do not convert to `Duration`
  exactly), `Duration` objects (about 500 ns an operation on the polyfill against 40, and an allocation each).
- `Backoff` computes the delays and does not wait: the wait of each user ends differently (`StreamWatcher::delay()`
  that `close()` ends in thesis/net, a `timeoutOr()` that `Client::stop()` ends in thesis/amqp).
  Rejected: a `retry(callable, Backoff)`, it would fix the wait and which failures are retried.
  The jitter takes between half and all of a delay ("equal jitter"), not from zero ("full jitter"):
  a reconnect right after a loss is a storm, and a floor keeps the attempts paced.
