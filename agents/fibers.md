# Fibers and the event loop

Read before keeping state per fiber or writing a test that depends on the order of callbacks.
The model of tasks, scopes and cancellation is in `packages/async/README.md`.

## Rules

- Per-fiber state is keyed by `\Fiber::getCurrent()` (and an object of its own for {main}), and the key is
  never kept in a local variable across code that may suspend: the fiber's stack then references the fiber
  itself, a cycle, and a fiber suspended there outlives its last reference, with all it holds, until the cycle
  collector runs. Get the key again every time it is needed, in `finally` too. A test catches it: start a fiber
  that suspends inside, drop it, assert that its `\WeakReference` is null
  (`fiberSuspendedInsideGoesAwayWithLastReference`).
- A fiber runs many tasks one after another (the pool of `Internal\Loop`): per-fiber state is gone when the code
  that has set it ends, restored in `finally` as `Scope::run()` and `Context` do, never left for the end of the fiber.
  `bindingInTaskDoesNotLeakIntoNextTaskInSameFiber` checks it for scoped values.
- Since php/php-src#24134 (PHP 8.6 from RC4, the branches of 8.4 and 8.5 after 8.4.16 and 8.5.12), the cycle
  collector does not free an object that holds a suspended fiber of a closure bound to it (php/php-src#25540).
  Our code avoids the shape, but a task of the user may have it (`$this->wait->park()` in a method), and its wait
  holds the event loop: `virtualTime()` releases the loop it drops (`Internal\Loop::release()`), so its descriptors
  close even then (`loopLeftWithFiberOfBoundClosureReleasesWhatItHolds`). Delete the release and this rule once
  a release of PHP has the fix: 8.6.0 does not.
- A fiber suspended with a value (`\Fiber::suspend($object)`) keeps it where the cycle collector does not look
  (PHP 8.6.0): a cycle through the fiber and that object is never freed, even after the caller has dropped
  the value. Suspend without one: `Internal\ContinuationImpl::park()` leaves itself in `Internal\Loop::$parked`
  instead, and `loopGoesAwayWithFiberLeftSuspended` checks it. Worth a report upstream.
- A test does not rely on the order of the fibers that become ready in one tick: on virtual time the loop
  shuffles them by a seed, random per test (`VirtualTimePlugin`), and a failed test prints its seed for
  `THESIS_SEED`. What a test asserts is then the set (`sort()` before comparing), or the events are moved
  to different times (`shield(fn() => delay(sec(1)))` in a cleanup). A test of one particular order of a tick
  runs it in a `virtualTime()` of its own without a seed, which keeps the order of waking.
