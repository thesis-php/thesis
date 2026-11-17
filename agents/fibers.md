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
- Since php/php-src#24134 (PHP 8.6 from RC4, the branches of 8.4 and 8.5 after 8.4.16 and 8.5.12), the cycle
  collector does not free an object that holds a suspended fiber of a closure bound to it (php/php-src#25540):
  a Revolt driver that has run is never freed, with its file descriptors. `virtualTime()` creates a driver
  per call, so it replaces the fibers of the driver it drops (`Internal\releaseFibers()`), and
  `driverThatHasRunGoesAwayAfterCall` checks it. Without it a run of the tests reaches `FD_SETSIZE`
  of `stream_select()`. An object of ours that keeps such a fiber has the same leak. Delete the workaround
  and this rule once a release of PHP has the fix.
- A test does not rely on the order of callbacks that become ready in one tick: the Revolt drivers
  (`StreamSelect`, `Uv`, `Ev`, `Event`) differ in it, and CI runs the tests on every one of them.
