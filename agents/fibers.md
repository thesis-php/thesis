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
- A test does not rely on the order of callbacks that become ready in one tick: the Revolt drivers
  (`StreamSelect`, `Uv`, `Ev`, `Event`) differ in it, and CI runs the tests on every one of them.
