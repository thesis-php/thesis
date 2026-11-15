# thesis/testo

Plugins for Testo that the tests of thesis packages and of their users need. The API is described in `README.md`.

This file is for agents changing this package. It is not loaded while an agent works in another package,
so how to *use* this package belongs in `README.md` or in a skill under `skills/<skill-name>/SKILL.md`.

## Invariants that must not be broken

- `VirtualTimePlugin` and `#[VirtualTime]` register the same `Internal\VirtualTimeInterceptor`, which conflicts
  with itself (`ConflictPolicy::First`): a test gets one `VirtualTime\run()`, whichever way it was asked for.
- The interceptor is outer to the lifecycle hooks and inner to data providers: the hooks share the test's clock,
  and every data set starts on a fresh one.
- The package only wires Testo to `Thesis\Async\VirtualTime\run()`: how the virtual clock behaves belongs to
  thesis/async and is not repeated or patched here.

## Decisions

- One package for all Testo integration of thesis, named after Testo rather than after one feature: a plugin
  for another package goes here too. Rejected: the plugin in `tests/` of thesis/async (users could not install it),
  in `src/` of thesis/async (every user of async would depend on Testo).
- thesis/async requires this package in `requireDev` for its own tests, and this package requires thesis/async:
  a cycle only through dev dependencies, which Composer resolves.
