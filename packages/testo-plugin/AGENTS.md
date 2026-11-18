# thesis/testo-plugin

Plugins for Testo that the tests of thesis packages and of their users need. The API is described in `README.md`.

## Invariants that must not be broken

- `VirtualTimePlugin` and `#[VirtualTime]` register the same `Internal\VirtualTimeInterceptor`, which conflicts
  with itself (`ConflictPolicy::First`): a test gets one `virtualTime()`, whichever way it was asked for.
- The interceptor is outer to the lifecycle hooks and inner to data providers: the hooks share the test's clock,
  and every data set starts on a fresh one.
- Every test on virtual time gets a random seed of the order of the fibers ready in one tick, or `THESIS_SEED`
  for all; a test that has not passed logs its seed to the channel `virtual-time`, so a run that failed once
  is replayed: `THESIS_SEED=<seed> make test P=<name> ARGS=--filter=<test>`.
- `EventLoopPlugin` and `#[EventLoop]` register `Internal\EventLoopInterceptor` the same way: the test runs as
  the body of a `group()` on the real clock, since a wait outside a run of the loop throws `LogicException`.
- `PhpErrorsPlugin` throws from its own handler, set around the run of the test, not through `exceptionally()`:
  the levels of `exceptionally()` do not reach a fiber started inside it, and the tasks of a test are such fibers.
  It respects `error_reporting()` (`@`), which PHP keeps per fiber. The monorepo applies it to every suite
  through `plugins` of the root `testo.php`, so `PhpErrorsTest` relies on it being there.
- The package only wires Testo to `Thesis\Async\virtualTime()` and `group()`: how the loop and the virtual clock
  behave belongs to thesis/async and is not repeated or patched here.

## Decisions

- One package for all Testo integration of thesis: a plugin for another package goes here too. Rejected:
  the plugin in `tests/` of thesis/async (users could not install it), in `src/` of thesis/async (every user
  of async would depend on Testo).
- Named `testo-plugin`: the tool, then its own word for an extension, so the name says what the package is
  and still fits once it grows. Rejected: `testo` (reads as Testo itself or its fork), a feature name like
  `testo-virtual-time` (outgrown by the next plugin), `testo-bridge` (a bridge of Testo brings a library
  into Testo, the other way round).
- Rejected: `PhpErrorsPlugin` replaced by `ErrorHandlerPlugin(failOnError: true)` of testo/error-handler.
  It fails the test after it ends, so the test runs on past the warning, and it takes its handler off the stack
  while the fiber of the test is suspended, when the tasks of the test run: their errors escape it.
  Testo registers it in every suite by default, inner to ours (`ORDER_CLOSE_TO_TEST`): it forwards an error
  to our handler first, ours throws, and nothing is left for it to capture.
- thesis/async requires this package in `requireDev` for its own tests, and this package requires thesis/async:
  a cycle only through dev dependencies, which Composer resolves.
