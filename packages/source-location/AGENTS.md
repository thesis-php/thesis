# thesis/source-location

`Location` identifies a place in source code, and `caller()` finds the call site outside a library.

## Invariants that must not be broken

- The package runs on PHP 8.4+, the rest of the monorepo on 8.6. The code, tests included, has no syntax or API
  newer than 8.4. PHPStan and Rector take the PHP of the monorepo and miss it, `make test-alone P=source-location`
  on 8.4 and 8.5 catches it. A rule of the 8.5 set of Rector that rewrites the code gets a `RectorSkip`
  in the manifest.
- The tests also run in the package installed alone, where the root of the project is the package, not the monorepo:
  a test takes the root from `InstalledVersions` and the package from `dirname(__DIR__)`, never the root
  by counting directories up.
- Nothing here throws: a location is taken while an error is reported (thesis/formatter) or while a trace is recorded
  (thesis/async), where an exception would change the behaviour it describes. `caller()` returns `null`
  where it finds nothing, `Location::ofFunction()` returns `null` for an internal function. A function that warns
  is called under `@` (the third way of `agents/errors.md`): the error handler of the application may turn
  the warning into an exception (`realpath()` of a root outside `open_basedir`).
- The code of `eval()` is located at the line of the outermost `eval()`: its file is "foo.php(7) : eval()'d code",
  a nested `eval()` appends more, so the pattern is lazy and not anchored at the end. It takes only a positive
  line (`[1-9]\d*`, else `Location::$line` would be 0) and spans a newline in the path (`/s`).
- `Caller::$callee` never has a NUL byte and names a closure as PHP does: the frame of a closure has the class
  it is declared in, which its name already holds. An anonymous class loses `\0<file>:<line>$<n>` everywhere
  in the name: PHP writes the full name of the class into the name of a closure declared in its method too.
  The pattern for a closure is greedy, since the path may hold `:<line>$<hex>` itself, and the name is cut
  at a NUL if PCRE fails: the invariant holds whatever `pcre.backtrack_limit` is.
- `install_path` of the root package of `InstalledVersions` exists since Composer 2.1:
  the manifest requires `composer-runtime-api` `^2.1`, not `^2.0`.
- `caller()` takes `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit)`: the arguments of the frames would hold
  the values of the program alive and cost a copy of each. A frame of the engine (no file) is passed over.
- `(string) Location` has the full path: thesis/formatter writes it into error messages as it did before the package.

## Decisions

- Rejected: PHP 8.2, like thesis/time. `Location::$shortFile` is a property hook of 8.4: a method instead breaks
  the API, a property computed in the constructor costs the `substr()` per location that the hook avoids.
  Before 8.4 the frame of a closure is `App\{closure}` with nothing of where it is declared (checked on 8.2
  and 8.3), so `callee()` would need a branch and `Caller::$callee` would differ by the PHP. Nobody below 8.4
  has asked for the package.
- `composer-runtime-api: ^2.1` has no test: it would need Composer 2.0 installed, and an assertion on the manifest
  repeats the manifest. The invariant above guards it.
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
