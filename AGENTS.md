# Thesis

An ecosystem of asynchronous PHP built on `revolt/event-loop` alone. It rethinks amphp and the older thesis packages
from scratch: it is not a wrapper around amphp and does not depend on it.

## Layout

- `packages/<name>/` is one package, published as `thesis/<name>` after the split. It holds `package.php` (the manifest),
  `src/`, `tests/`, `README.md`, `AGENTS.md` and optionally `skills/`.
- `tools/monorepo/` is the monorepo tooling (`bin/monorepo`), see its `AGENTS.md`.
- `monorepo.php` is the root config: PHP constraint, authors, tools of the root.
- `docs/roadmap.md` holds the plans, the migration of the old packages and the rejected alternatives.
  Read it before proposing a change to how the monorepo works.

## Instructions are layered

- This file is about the whole monorepo. Keep it short: it is loaded in every session.
- `packages/<name>/AGENTS.md` is loaded only when you work in that package. It holds invariants and decisions
  for those who *change* the package.
- How to *use* a package from another package is in its `README.md` or its `skills/`. Read them before
  using a package you have not used yet in this session: its `AGENTS.md` will not be loaded for you.
- Write `AGENTS.md` only, never `CLAUDE.md`: a `CLAUDE.md` makes Claude Code ignore every `AGENTS.md`.

## Manifests and generated files

`packages/<name>/package.php` returns a `Thesis\Monorepo\Manifest` and is the only place to declare a package's
description, layer and dependencies. Generated from the manifests and `monorepo.php` by `make sync`, never edit them by hand:

- `packages/<name>/composer.json`,
- the root `composer.json`: `require`, `require-dev`, `replace`, `autoload`, `autoload-dev`,
- `skills.json` (`sources`),
- `testo.php`: one suite per package, named after its directory. A package that needs Testo plugins
  returns a list of them from `packages/<name>/testo.plugins.php`,
- the package catalog below.

## Dependencies

- One `vendor/` and one lock for the whole monorepo. The root `composer.json` is the union of the packages.
- An external dependency has one constraint across the monorepo: bump it in every manifest at once.
- A package lists monorepo packages it needs in `uses` (and `usesDev`), by directory name.
  The code of a package may only reference namespaces of itself and of its `uses`: `make check` verifies this,
  because the shared autoloader would hide a missing dependency.

## Commands

Everything runs in Docker through `make`. `P=<name>` narrows a command to one package. Use it while you work,
the whole monorepo is for the final check.

- `make test P=async`, `make phpstan P=async`, `make fixer P=async`, `make rector P=async`
- `make new-package NAME=byte-buffer LAYER=primitive DESC='Growable byte buffer'`
- `make sync` after changing a manifest or `monorepo.php`
- `make check` runs everything CI runs

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
- A path to a parent directory is `dirname(__DIR__)` or `dirname(__DIR__, 2)`, never `__DIR__.'/..'`:
  a path without `..` reads well in errors and can be compared as a string with other paths.
- The pipe operator (`|>`) is for an explicit pipeline of several transformations of one value, where it replaces
  deep nesting or a chain of temporary variables. A call or two of nesting stays a plain call: `f(g($x))`,
  not `$x |> g(...) |> f(...)`.
- A call with more than two arguments puts them one per line with a trailing comma. `make rector` names them
  (`tools/rector/MoreThanTwoArgumentsStrategy.php`), but does not wrap the lines.
- A multiline chain of method calls ends with `;` on its own line, so a call is added or removed without touching
  the previous line. `make fixer` enforces it.
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
- Every top-level symbol (class, interface, trait, enum, function, constant) in `src/` is marked either `@api`
  or `@internal` in its PHPDoc, never both. Internal symbols live in the `Internal` sub-namespace of the package
  (`Thesis\Async\Internal\...`) and nothing else does, so the namespace alone tells a user what is safe to depend on.
  Tests are not marked: they are not part of the API. `make check` verifies this in `packages/*/src`
  and `tools/monorepo/src`.
- Static constructors go first in a class, the destructor goes last. The constructor body uses its parameters,
  not the promoted properties (`$x`, not `$this->x`) where possible.
- A PHPDoc of a declaration (class, method, function, property, constant, enum case) is multi-line,
  even with one tag. An inline annotation in code is one line: `/** @var list<int> $ids */`.
  PHP CS Fixer enforces both (`phpdoc_line_span`).
- A comment explains what the code cannot: a non-obvious reason, a constraint, a trap. If it retells the code,
  rename the variable, method or class instead and delete the comment. PHPDoc types are not comments,
  they stay.
- Functions and constants are loaded by one file per scope: `src/autoload.files.php` in `files` of the manifest,
  `tests/autoload.files.php` in `devFiles`. A new file of functions is added there with `require_once`,
  so neither the manifest nor the autoloader changes.

### Tests

- No traits for test helpers: plain functions (in `tests/autoload.files.php`) or a base class.
- Write the failing test first, then the fix.

## Changing a package others use

When you change the public API of a package, find its users (`grep -rl 'Thesis\\Async\\' packages/*/src`) and fix them
in the same change. Atomic changes across packages are the reason this is a monorepo.

## Packages by layer

Layers go from the lowest to the highest. A package may depend on packages of its own layer or of a lower one,
without cycles. Layers are declared in `tools/monorepo/src/Layer.php`.

<!-- packages:start (generated by bin/monorepo sync, do not edit) -->

### primitive

Pure building blocks: bytes, time, small value objects. No event loop.

No packages yet.

### runtime

Structured concurrency on top of revolt/event-loop.

- `async`: Structured concurrency for PHP fibers: cancellation is a property of the task tree.

### io

Streams, sockets, files, processes.

No packages yet.

### protocol

Clients and servers of wire protocols.

No packages yet.

### application

Frameworks and integrations: DI, message bus, ORM, console.

No packages yet.

<!-- packages:end -->
