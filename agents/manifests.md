# Manifests, dependencies and packages

Read before changing a manifest or `monorepo.php`, adding a dependency, a package or a file of functions or constants,
or changing the public API of a package that others use. How the generator works is in `tools/monorepo/AGENTS.md`.

## Rules

### Manifests and generated files

- `packages/<name>/package.php` returns a `Thesis\Monorepo\Manifest` and is the only place to declare a package's
  description and dependencies. `monorepo.php` is the root config: PHP constraint, authors, tools
  and composer plugins of the root.
- Generated from the manifests and `monorepo.php` by `make sync`, never edited by hand:
  - `packages/<name>/composer.json` and the root `composer.json`,
  - `LICENSE` of the root and of every package, from the year in the manifest,
  - the head of `packages/<name>/README.md` (the title, the badges, the note of the split)
    and `packages/<name>/CONTRIBUTING.md`,
  - `packages/<name>/.split`: what the split repository, and so every install, has (`repositories.md`),
  - `skills.json` (`sources`),
  - the package catalog in the root `AGENTS.md` and the table of packages in the root `README.md`,
    from the descriptions.
- The configs of the tools take what a package says about itself from the manifests (`Monorepo::load(__DIR__)`),
  never from the layout of `packages/`: the exceptions (`optional`, `skip`, through `Tools`), the Testo suite
  of a package with `tests/` and its plugins (`testoPlugins`). The settings of a tool itself stay in its config:
  the plugins of every suite are in `testo.php` (`PhpErrorsPlugin`: a PHP error fails the test).
- `keywords` of a manifest and of `monorepo.php` go to `composer.json` and are the topics of the repository
  on GitHub (`repositories.md`), so `make sync` checks them by the rules of a topic.
- **`description` and `keywords` follow the features.** A change that adds, removes or renames what a package does
  (a protocol, a transport, a kind of API) updates the `description` and `keywords` of its manifest
  in the same change, and those of `monorepo.php` when it changes what the ecosystem offers (a new package).
  They are how the package is found on Packagist and GitHub: a stale one hides a feature or promises a removed one.
- An exception of a package to a tool is declared in its manifest (`optional`, `skip`), never in a config.
  PHPStan errors are ignored in the code instead, by `@phpstan-ignore identifier (reason)`.

### A package

- `packages/<name>/` holds `package.php`, `src/`, `tests/`, `README.md`, `CHANGELOG.md` (`changelogs.md`),
  `AGENTS.md`, `LICENSE`, `CONTRIBUTING.md` and optionally `skills/` and `benchmarks/` (`benchmarks.md`).
  Without `tests/` (`phpstan-extension`, checked by `make phpstan`) the package gets no Testo suite
  and no `autoload-dev`.
- `rootEntry: true` of the manifest puts the entry point named after the package in the root namespace
  (`php.md`): the psr-4 prefix of `src/` and `tests/` becomes `Thesis\`, so the class is `src/Dispatcher.php`,
  a function is in `src/api.php`, its test is `tests/DispatcherTest.php`, and the rest moves to `src/<Name>/`
  and `tests/<Name>/` (`src/Dispatcher/Internal/`, `tests/Exceptionally/helpers.php`). The root `composer.json`
  lists every such `src/` and `tests/` under `Thesis\`.
- A new package is made by hand after an existing one, then `make sync`. Its `AGENTS.md` starts with
  the sections "Invariants that must not be broken" and "Decisions" (what was rejected and why).
- Functions, constants and code run at load (the warning of `async` on Windows) live in eager files:
  PHP files of `src/` and `tests/` without a class, an interface, a trait or an enum.
  The functions and constants of a namespace are in `api.php`, those of an `Internal` namespace in `internal.php`,
  test helpers in `tests/helpers.php`. Only code run at load gets a file of its own.
  `make sync` finds them (`EagerFiles`) and writes them to `autoload.files` and `autoload-dev.files`,
  nothing lists them by hand, so CI fails on a new one until `make sync` is run. A deleted one breaks
  `vendor/autoload.php` and with it `make sync`: remove its line from the `composer.json` files by hand
  and run `composer dump-autoload` first. A constant declared next to a class (`const ROOT_SCOPE = new Scope()`)
  is loaded with it by PSR-4. The files are sorted by path and must not depend on each other at load time.
  `files`/`devFiles` of the manifest list eager files outside `src/` and `tests/`: a file that belongs
  to a directory of its own and goes away with it (`time/polyfill/load.php`, `amqp/codegen/generate.php`).
- A function of `internal.php` is a helper that several classes or `api.php` share. Functions with helpers
  of their own that nobody else calls are a class with `private static` helpers instead
  (`Amqp\Internal\Protocol\Fields`): PHP has no private functions.

### Dependencies

- A package declares what it needs in `require` and `requireDev` of its manifest, with its own constraints.
  Which packages may depend on which is decided case by case, the tooling enforces no structure.
- A monorepo package is required as `'thesis/async' => '*'`: the generator writes the range of the series
  of the dependency instead (`^0.1`), so a release does not touch the manifests (`versions.md`). Every manifest
  declares the series of its package, `version: '0.1'`.
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

- Rejected for the root entry: a free `namespace:` prefix in the manifest (it let `exceptionally` put
  `Thesis\Internal\` and its test helpers in the root, where they would collide with those of another package),
  and `Thesis\<Name>\` for `src/` with the root class in `classmap` (it saves `src/<Name>/`, but
  `composer dump-autoload -o` of every user warns that the file does not comply with psr-4).
- Rejected for eager files: one `autoload.files.php` per scope that `require_once`s them (most held
  a single line, a forgotten line showed only as an undefined function in the tests), listing every file
  in the manifest (bookkeeping that the code already says), runtime `glob()` (a cost for every user,
  magic by file name), static methods instead of functions (a worse API), `functions.php` (holds constants too).
- Rejected: no functions in `Internal` so that the autoloader loads them lazily. An eager file costs microseconds
  once per process, and the processes of this ecosystem live long. Most internal functions serve `api.php`,
  which is eager anyway, so a class would only rename them.
- Rejected: `testo.php` generated by `sync`. It only repeated the directories of `packages/` and `tools/`,
  and the plugins of every suite had to live in a handwritten `testo.plugins.php` of the root beside it.
  Rejected too: `packages/<name>/testo.plugins.php`, a second file of what the package declares.
