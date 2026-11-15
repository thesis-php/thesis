# Thesis

An ecosystem of asynchronous PHP built on `revolt/event-loop` alone. It rethinks amphp and the older thesis packages
from scratch: it is not a wrapper around amphp and does not depend on it.

## Layout

- `packages/<name>/` is one package, published as `thesis/<name>` after the split. It holds `package.php` (the manifest),
  `src/`, `tests/`, `README.md`, `AGENTS.md`, `LICENSE` and optionally `skills/`.
  A new package is made by hand after an existing one, then `make sync`. Its `AGENTS.md` starts with
  the sections "Invariants that must not be broken" and "Decisions" (what was rejected and why).
- `tools/monorepo/` is the monorepo tooling (`bin/monorepo`), see its `AGENTS.md`.
- `tools/php-cs-fixer/` holds our custom PHP CS Fixer rules, see its `AGENTS.md`.
- `tools/split/` splits `packages/<name>` into the read-only `thesis-php/<name>` in CI, see its `AGENTS.md`.
- `monorepo.php` is the root config: PHP constraint, authors, tools and composer plugins of the root.
  `platform` there is required by every package: all of them are 64-bit only (`php-64bit`), so code may rely
  on 64-bit int (nanoseconds, `pack('J')`) without checking `PHP_INT_SIZE`. A manifest does not repeat it.
- `docs/adr/NNNN-<slug>.md` holds decisions that span packages (Context, Decision, Consequences, Rejected).
  A decision of one package goes to the "Decisions" section of its `AGENTS.md` instead. An ADR is not rewritten
  when the decision changes: a new one replaces it, and the old one gets `Status: superseded by NNNN`.
  Read the accepted ADRs before designing a package they concern.

## Instructions are layered

- This file is about the whole monorepo. Keep it short: it is loaded in every session.
- `packages/<name>/AGENTS.md` is loaded only when you work in that package. It holds invariants and decisions
  for those who *change* the package.
- How to *use* a package from another package is in its `README.md` or its `skills/`. Read them before
  using a package you have not used yet in this session: its `AGENTS.md` will not be loaded for you.
- Write `AGENTS.md` only, never `CLAUDE.md`: a `CLAUDE.md` makes Claude Code ignore every `AGENTS.md`.
- An important finding (an invariant, a decision, a trap, a rejected approach) goes into the right `AGENTS.md`
  in the same change, without being asked. When we rethink something, rewrite the existing text instead of
  appending a correction: the file describes how things are now, not how we got here.
- What a tool enforces (`make fixer`, `make rector`) is not described here: the tool fixes the code anyway.
  Why a custom rule is the way it is goes to the `AGENTS.md` next to the rule.
- Keeping `AGENTS.md` files in shape is critical: they are the only memory that survives between sessions.
  Every rule is short, current and in the section it belongs to. Delete what became obsolete, merge duplicates.

## Manifests and generated files

`packages/<name>/package.php` returns a `Thesis\Monorepo\Manifest` and is the only place to declare a package's
description and dependencies. Generated from the manifests and `monorepo.php` by `make sync`, never edit them by hand:

- `packages/<name>/composer.json`,
- the root `composer.json`,
- `LICENSE` of the root and of every package, from the year in the manifest,
- `packages/<name>/.gitattributes`: the archive that composer installs has only `src/`, `composer.json`, `LICENSE`,
  `README.md`, `skills/` and the paths in `dist` of the manifest (a polyfill, resources that `src/` loads),
- `skills.json` (`sources`),
- `testo.php`: one suite per package and per `tools/<name>/tests`, named after the directory.
  A package that needs Testo plugins returns a list of them from `packages/<name>/testo.plugins.php`,
- `tools/manifests.php`: what the configs of fixer, rector and dependency-analyser take from the manifests.
  An exception of a package to a tool is declared in its manifest (`optional`, `skip`), never in `tools/*.php`.
  PHPStan errors are ignored in the code instead, by `@phpstan-ignore identifier (reason)`,
- the package catalog below.

## Dependencies

- A package declares what it needs in `require` and `requireDev` of its manifest, with its own constraints.
  Which packages may depend on which is decided case by case, the tooling enforces no structure.
- A monorepo package is required as `'thesis/async' => '*'`: the generator writes `Config::$version` instead,
  so a release does not touch the manifests.
- One `vendor/` and one lock for the whole monorepo. The root `composer.json` takes, for every external dependency,
  the constraint that lies within all the others, so no package is tested with a version it does not allow.
  When there is none, `make sync` fails: narrow one of the constraints.
- The shared autoloader hides a dependency missing in a manifest: the code of a package references only itself
  and the monorepo packages it requires (`requireDev` counts for `tests/`).

## Commands

Everything runs in Docker through `make`. `P=<name>` narrows a command to one package. Use it while you work,
the whole monorepo is for the final check.

- `make test P=async`, `make phpstan P=async`, `make fixer P=async`, `make rector P=async`
- `make sync` after changing a manifest or `monorepo.php`
- `make check` runs the checks of CI. CI also runs `make sync` and fails on any change in git,
  so commit the generated files

## Git

- Work and commit directly on `main`, do not create branches: the monorepo is in early development,
  branches only get in the way. This rule will change once others contribute.
- Commit only when asked, with a short imperative subject like the existing history. The user pushes.
- Undo a pushed commit with `git revert`, never by rewriting history.
- No `Claude-Session` or other trailers in commit messages and PR descriptions.
- An empty directory is kept by an empty `.gitignore`, never by `.gitkeep`. Delete it once the directory has files.

## PHP code

- Lines are at most 120 characters, comments and tests included. The only exception is a line that cannot be wrapped:
  a single string literal (an exception message, an expected error) or a URL.
- Prefer language features and standard library APIs of the target PHP over older equivalents and userland packages:
  first-class callables (`f(...)`) instead of wrapper closures, `Uri\WhatWg\Url` instead of `parse_url()`
  or a URL library.
- A duration in a public API (a timeout, a delay, a TTL, an interval) is `Time\Duration`, created and converted
  with the functions of `thesis/time` (`sec(5)`, `ms(250)`, `toSecFloat()`). Never a bare `int`/`float` of seconds
  or milliseconds, and never `float|Duration`: the unit of a number is only a convention, and a union can be widened
  later but not narrowed. Inside, time is `int` nanoseconds: exact like `Duration` (`ns()`/`toNs()` convert
  without a loss) and as cheap as a float, while a `Duration` object costs an allocation per operation.
  Convert at the boundary to what the underlying API takes (Revolt: float seconds, `$ns / 1e9`).
- A path to a parent directory is `dirname(__DIR__)` or `dirname(__DIR__, 2)`, never `__DIR__.'/..'`:
  a path without `..` reads well in errors and can be compared as a string with other paths.
- The pipe operator (`|>`) is for an explicit pipeline of several transformations of one value, where it replaces
  deep nesting or a chain of temporary variables. A call or two of nesting stays a plain call: `f(g($x))`,
  not `$x |> g(...) |> f(...)`.
- A call stays on one line while it fits in 120 characters, named arguments or not:
  `self::bytes($random, min: 0, max: 40)`. Otherwise its arguments go one per line with a trailing comma,
  and every argument is named, so the names form a column. The exception is a call whose arguments go
  to a variadic parameter (`\sprintf()`, `...$args`): PHP cannot name them.
- `_` in a numeric literal groups a magnitude, it is placed by hand (the fixer rule put it everywhere: `2_026`).
  A decimal integer of 5 digits and more is grouped by thousands (`10_000`, `65_536`, `1_048_576`), a shorter one
  is not (`1000`, `3600`, `8192`). A hex literal of up to 4 digits has no `_` (`0xFFFF`, `0x8000`), a longer one
  is grouped by bytes (`0x7F_FF_FF_FF`, `0x01_02_03_04`). Never in a number that names something rather than
  counts (a year, a port, a protocol code: `$port <= 65535`) and never in a fraction.
- Don't neglect named arguments where they help reading: a flag or a bare literal (`strict: true`, `limit: 3`),
  neighbours that are easy to swap (`actual:`/`expected:`), a call of our own code with many parameters,
  a data object built field by field (a DTO, a manifest), a rarely used function whose parameters nobody remembers
  (`proc_open()`, `stream_socket_pair()`). Where the call already reads well, skip them:
  `substr($bytes, $offset, $length)`. Rejected: a mechanical rule like "name every argument of a call with more
  than two", it is noise around the standard library.
- PHPStan (`level: max`, strict rules) is part of the contract. A constraint expressed in a PHPDoc type
  (`non-empty-array`, `positive-int`, ...) needs no runtime guard and no test for violating it.
  Don't flag "PHPDoc isn't enforced at runtime" in reviews: tighten the type instead.
- A public API accepts `callable` with a signature (`callable(T): void`), not `Closure`: users may pass
  invokable objects, `[$object, 'method']` and the like.
- A method that doesn't use `$this` is `static` and is called via `self::`, in hand-written and generated code alike.
  Exceptions only where the language forbids it: interface implementations and overrides of instance methods.
- No static classes that pass complex state between their methods (the same arguments to every method,
  accumulators by reference). Write an object-function instead: a static entry point that creates a private
  instance, keeps the state in its properties and returns the result, e.g. `Validator::validate($monorepo)`.
- State that only one function needs (a memoized value, a cache, a lazily created instance) is a `static` variable
  inside that function, not a static property: it sits next to the only code that uses it, and since PHP 8.3
  it is initialized with any expression. A static property is only for state shared by several methods.
- No return types on closures and arrow functions: `static fn() => new X()`, not `static fn(): X => new X()`.
- Named typed properties instead of id-indexed arrays (`$this->values[0]`). Use an array only for genuinely dynamic sets.
- `readonly` wherever possible: `final readonly class` for immutable classes, `readonly` on individual properties
  for partially mutable ones.
- No setters: writing a value is assigning a property. A property anyone may change with nothing to check
  is plain `public` (`$clock->now = $time`, not `$clock->set($time)`), one that changes only inside is
  `public private(set)` instead of a getter, one that never changes is `readonly`. A `set` hook is only
  for checking or normalizing on write. A method is for an operation with a meaning of its own
  (`advance($duration)`), for writing that waits (it may suspend the fiber) or returns something,
  and where an interface requires it.
- Every top-level symbol (class, interface, trait, enum, function, constant) in `src/` is marked either `@api`
  or `@internal` in its PHPDoc, never both. Internal symbols live in the `Internal` sub-namespace of the package
  (`Thesis\Async\Internal\...`) and nothing else does, so the namespace alone tells a user what is safe to depend on.
  Tests are not marked: they are not part of the API.
- Static constructors go first in a class, the destructor goes last. The constructor body uses its parameters,
  not the promoted properties (`$x`, not `$this->x`) where possible.
- Code is read at a glance, not decoded. If a reader has to work out what an expression means (rearrange
  an inequality, trace a bit trick, recount a bound), rewrite it to say what it means: a plainer form,
  a named variable or a function. Obvious and a line longer beats clever and short. Only a benchmark
  keeps a trick (the hot path of `Buffer`), and then a comment on that line says what it computes.
- A comment explains what the code cannot: a non-obvious reason, a constraint, a trap. If it retells the code,
  rename the variable, method or class instead and delete the comment. PHPDoc types are not comments,
  they stay.
- Functions and constants are loaded by one file per scope: `src/autoload.files.php` in `files` of the manifest,
  `tests/autoload.files.php` in `devFiles`. A new file of functions is added there with `require_once`,
  so neither the manifest nor the autoloader changes. Only files without a class-like declaration go there:
  a constant declared next to a class (`const ROOT_SCOPE = new Scope()`) is loaded with it by PSR-4.
  There is no check for a forgotten `require_once`: the tests fail on an undefined function.
  Rejected: listing every file in the manifest (each new file rebuilds the autoloader), runtime `glob()`
  (a cost for every user, magic by file name), static methods instead of functions (a worse API).

### Tests

- No traits for test helpers: plain functions (in `tests/autoload.files.php`) or a base class.
- Write the failing test first, then the fix.

## Changing a package others use

When you change the public API of a package, find its users (`grep -rl 'Thesis\\Async\\' packages/*/src`) and fix them
in the same change. Atomic changes across packages are the reason this is a monorepo.

## Packages

<!-- packages:start (generated by bin/monorepo sync, do not edit) -->

- `async`: Structured concurrency for PHP fibers: cancellation is a property of the task tree. Requires: time.
- `io`: Fast binary I/O: byte buffer with typed big/little-endian encoding, reader and writer interfaces.
- `net`: Non-blocking TCP and Unix sockets with TLS and an async DNS resolver, cancelled by the task tree. Requires: async, io, time.
- `time`: Functions for Time\Duration of PHP 8.6 and a polyfill of it for PHP 8.5, PSR-20 clocks.

<!-- packages:end -->
