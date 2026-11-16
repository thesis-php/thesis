# Manifests, dependencies and packages

Read before changing a manifest or `monorepo.php`, adding a dependency, a package or a file of functions,
or changing the public API of a package that others use. How the generator works is in `tools/monorepo/AGENTS.md`.

## Rules

### Manifests and generated files

- `packages/<name>/package.php` returns a `Thesis\Monorepo\Manifest` and is the only place to declare a package's
  description and dependencies. `monorepo.php` is the root config: PHP constraint, `platform`, authors, tools
  and composer plugins of the root.
- Generated from the manifests and `monorepo.php` by `make sync`, never edited by hand:
  - `packages/<name>/composer.json` and the root `composer.json`,
  - `LICENSE` of the root and of every package, from the year in the manifest,
  - `packages/<name>/.gitattributes`: the archive that composer installs has only `src/`, `composer.json`, `LICENSE`,
    `README.md`, `skills/` and the paths in `dist` of the manifest (a polyfill, resources that `src/` loads),
  - `skills.json` (`sources`),
  - `testo.php`: one suite per `packages/<name>/tests` and `tools/<name>/tests`, named after the directory.
    A package that needs Testo plugins returns a list of them from `packages/<name>/testo.plugins.php`,
  - `tools/manifests.php`: what the configs of fixer, rector and dependency-analyser take from the manifests,
  - the package catalog in the root `AGENTS.md`, from the descriptions.
- An exception of a package to a tool is declared in its manifest (`optional`, `skip`), never in `tools/*.php`.
  PHPStan errors are ignored in the code instead, by `@phpstan-ignore identifier (reason)`.
- CI runs `make sync` and fails on any change in git, so the generated files are committed.

### A package

- `packages/<name>/` holds `package.php`, `src/`, `tests/`, `README.md`, `AGENTS.md`, `LICENSE` and optionally
  `skills/`. Without `tests/` (`phpstan-extension`, checked by `make phpstan`) the package gets no Testo suite
  and no `autoload-dev`. A package that continues an older repository of the same name keeps its `CHANGELOG.md`
  there (`exceptionally`).
- A new package is made by hand after an existing one, then `make sync`. Its `AGENTS.md` starts with
  the sections "Invariants that must not be broken" and "Decisions" (what was rejected and why).
- Functions and constants are loaded by one file per scope: `src/autoload.files.php` in `files` of the manifest,
  `tests/autoload.files.php` in `devFiles`. A new file of functions is added there with `require_once`,
  so neither the manifest nor the autoloader changes. Only files without a class-like declaration go there:
  a constant declared next to a class (`const ROOT_SCOPE = new Scope()`) is loaded with it by PSR-4.
  There is no check for a forgotten `require_once`: the tests fail on an undefined function.

### Dependencies

- A package declares what it needs in `require` and `requireDev` of its manifest, with its own constraints.
  Which packages may depend on which is decided case by case, the tooling enforces no structure.
- A monorepo package is required as `'thesis/async' => '*'`: the generator writes `Config::$version` instead,
  so a release does not touch the manifests (`versions.md` replaces it with the series of the dependency).
- One `vendor/` and one lock for the whole monorepo. The root `composer.json` takes, for every external dependency,
  the constraint that lies within all the others, so no package is tested with a version it does not allow.
  When there is none, `make sync` fails: narrow one of the constraints.
- The shared autoloader hides a dependency missing in a manifest: the code of a package references only itself
  and the monorepo packages it requires (`requireDev` counts for `tests/`).

### Changing a package others use

- When you change the public API of a package, find its users (`grep -rl 'Thesis\\Async\\' packages/*/src`)
  and fix them in the same change. Atomic changes across packages are the reason this is a monorepo.
- `phpstan-extension` gives the types that PHPDoc cannot express (a type per key of `all()`), knowing
  the functions of other packages by name and semantics. The PHPDoc stays the contract and is fixed in the package
  wherever generics can express the type. A change of a function the extension describes changes it too:
  its `types/` fixtures fail in `make phpstan` otherwise.

## Decisions

- Rejected for the files of functions: listing every file in the manifest (each new file rebuilds the autoloader),
  runtime `glob()` (a cost for every user, magic by file name), static methods instead of functions (a worse API).
