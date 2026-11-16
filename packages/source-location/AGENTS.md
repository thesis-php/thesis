# thesis/source-location

Where in the source code: `Location` and `caller()`. The API is described in `README.md`.

This file is for agents changing this package. It is not loaded while an agent works in another package,
so how to *use* this package belongs in `README.md` or in a skill under `skills/<skill-name>/SKILL.md`.

## Invariants that must not be broken

- Nothing here throws: a location is taken while an error is reported (thesis/formatter) or while a trace is recorded
  (thesis/async), where an exception would change the behaviour it describes. `caller()` returns `null`
  where it finds nothing, `Location::ofFunction()` returns `null` for an internal function.
- The code of `eval()` is located at the line of the outermost `eval()`: its file is "foo.php(7) : eval()'d code",
  a nested `eval()` appends more, so the pattern is lazy and not anchored at the end.
- `caller()` takes `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit)`: the arguments of the frames would hold
  the values of the program alive and cost a copy of each. A frame of the engine (no file) is passed over.
- `(string) Location` has the full path: thesis/formatter writes it into error messages as it did before the package.

## Decisions

- The name stays `thesis/source-location` with `Thesis\SourceLocation\Location`: the repetition is only in `use`,
  the code reads `Location`, and the monorepo already has `Thesis\Cache\Cache`. Rejected: `thesis/source`
  (too vague: a data source, an event source), `thesis/location` (reads as geolocation, repeats anyway),
  `thesis/backtrace` and `thesis/callsite` (cover `caller()` but not `Location::ofFunction()`).
- Extracted from `Internal\location()` of thesis/formatter and `Location`/`caller()` of thesis/dic, which had
  a greedy pattern anchored at the end and located a nested `eval()` at the inner one. thesis/dic takes this package
  when it moves to the monorepo.
- The root of the project is the root package of `Composer\InstalledVersions`, resolved by `realpath()`:
  thesis/dic took the directory above the first vendor of `ClassLoader`, which is a guess, while the root package
  is named by Composer. Without Composer the path is not shortened.
- `Location` is `final`, not `final readonly`: `$shortFile` is a hooked property, and a hooked property cannot be
  readonly. It is computed on read, so a location taken on a hot path (each wait of a traced task) costs no
  `substr()` until it is shown.
- `Caller::$callee` is taken from the same frame as the location: thesis/async names the wait of a task
  (`Thesis\Async\delay`) with it, without a second backtrace.
