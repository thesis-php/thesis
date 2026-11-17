# thesis/testo-plugin

Plugins for Testo that the tests of thesis packages and of their users need. The API is described in `README.md`.

This file is for agents changing this package. It is not loaded while an agent works in another package,
so how to *use* this package belongs in `README.md` or in a skill under `skills/<skill-name>/SKILL.md`.

## Invariants that must not be broken

- `VirtualTimePlugin` and `#[VirtualTime]` register the same `Internal\VirtualTimeInterceptor`, which conflicts
  with itself (`ConflictPolicy::First`): a test gets one `virtualTime()`, whichever way it was asked for.
- The interceptor is outer to the lifecycle hooks and inner to data providers: the hooks share the test's clock,
  and every data set starts on a fresh one.
- `PhpErrorsPlugin` throws from its own handler, set around the run of the test, not through `exceptionally()`:
  the levels of `exceptionally()` do not reach a fiber started inside it, and the tasks of a test are such fibers.
  It respects `error_reporting()` (`@`), which PHP keeps per fiber. The monorepo applies it to every suite
  through the root `testo.plugins.php`, so `PhpErrorsTest` relies on it being there.
- The package only wires Testo to `Thesis\Async\virtualTime()`: how the virtual clock behaves belongs to
  thesis/async and is not repeated or patched here.

## Decisions

- One package for all Testo integration of thesis: a plugin for another package goes here too. Rejected:
  the plugin in `tests/` of thesis/async (users could not install it), in `src/` of thesis/async (every user
  of async would depend on Testo).
- Named `testo-plugin`: the tool, then its own word for an extension, so the name says what the package is
  and still fits once it grows. Rejected: `testo` (reads as Testo itself or its fork), a feature name like
  `testo-virtual-time` (outgrown by the next plugin), `testo-bridge` (a bridge of Testo brings a library
  into Testo, the other way round).
- thesis/async requires this package in `requireDev` for its own tests, and this package requires thesis/async:
  a cycle only through dev dependencies, which Composer resolves.
