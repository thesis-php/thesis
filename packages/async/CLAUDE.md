# thesis/async

An experimental structured concurrency library on top of `revolt/event-loop`.
The model, the API and the guarantees are described in `README.md` — read it first.

## Invariants that must not be broken

- Every wait goes through `suspend()`. Do not call `EventLoop::getSuspension()` directly in new code
  (the only exception is `Waker::park()`).
- Cancellation is level-triggered: while a scope is cancelled, every `suspend()` in it throws `Cancelled`.
- `Cancelled extends \Error`. `Cancelled::$origin` is the outermost cancelled scope; `timeout()` and `group()` use it
  to tell their own cancellation from a foreign one.
- If the event source fires before the cancellation, `suspend()` returns the value instead of throwing.
- `Group::join()` is uncancellable. Unstructured spawn (`async()`) is absent on purpose — do not add it.
- Cancelling a task does not fail its group; a task failure does, and the first failure is the one rethrown.
- Revolt callbacks must return `null`: use a closure with a body (`function () { $waker->resume(); }`), not `fn() => $waker->resume()`.
- A parent scope holds its children strongly: every created `Scope` must be closed with `close()`.
