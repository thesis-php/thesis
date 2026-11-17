# thesis/phpstan-extension

The PHPStan extension of all thesis packages: the types that their PHPDoc cannot express.
How to use it is in `README.md`.

## Invariants that must not be broken

- The PHPDoc of a package stays its contract: correct without the extension, only wider. An extension narrows it,
  never contradicts it, and is written only where the PHPDoc cannot be made precise (a template, a conditional
  return type): a type that generics express is fixed in the package.
- An extension returns `null` (the type of the PHPDoc) whenever it is not sure: a value that is not surely
  a callable, an unpacked argument, an iterable that is not an array. A wrong call keeps its error from PHPStan,
  and a type the extension cannot prove is never narrower than the PHPDoc promises.
- A shape describes the keys in their order, and so does a list. An extension carries the shape, `list` or
  `non-empty-` of the input to the result only for a function that returns a value for every key of its input,
  in its order: `all()` and `map()` do. `some()` returns the results in the order they succeeded.
- `allSettled()`: every key is optional in both arrays, which of them a key goes to is known only at runtime.
  For the same reason it says nothing of an input that is not a shape: a failure leaves a hole in a list.
- `map()` of a generator keeps the PHPDoc type: a generator may repeat a key.
- There are no rules now, only types. A rule, when one is needed, checks only what the package cannot check itself
  or checks only at runtime, and reports an error at the line of what is wrong (an attribute, not its method),
  with an identifier `thesis.<camelCase>`, so a user ignores exactly it.

## Decisions

- Named after what it is, the word of PHPStan and the type of composer. Rejected: `phpstan-plugin` (a plugin
  is the word of Psalm), `phpstan` (reads as PHPStan itself in `thesis/phpstan`), `thesistan` (a name to learn
  instead of one that says what it is).
- One package for all packages: one install, and a user gets the types of the packages they use. It requires
  none of them: a function is recognized by its name (`'Thesis\Async\all'`), the packages it describes are
  in `requireDev` only, for the fixtures. Rejected: an extension inside each package (its `src/` would reference
  PHPStan, a tool of the users, and a user would include a config per package); a package of extensions
  per package (`thesis/async-phpstan`, an install per package for the same result); stubs (a stub is a PHPDoc
  too, it cannot express more than the package itself).
- Rejected: requiring `nikic/php-parser`. It is a part of the API of PHPStan, bundled unprefixed in its phar
  for extensions, so `phpstan/phpstan` is the dependency. A copy in `vendor/` may be loaded instead of the bundled
  one, and its constraint could not follow the version inside each release of PHPStan.
- Classes of the extension are `@internal` (`Internal\`, flat until there are many): users include `extension.neon`,
  never reference them.
- The monorepo analyses itself with the extension: `tools/phpstan.neon` includes `extension.neon`,
  not through phpstan/extension-installer.
- `map()` gives every key the same `R` of the PHPDoc: `$fn` is one callable, its return type for each item
  is not known. Rejected: inferring `$fn` per item type, PHPStan has no API for it.
- Nothing for the functions whose generics are already precise: `race()` (the union of the template),
  `timeout()`, `timeoutOr()`, `group()`, `shield()`, `synchronized()`, `Group::spawn()`.
  Nothing for `some()`: the best honest type is its PHPDoc, a shape or a list would lie about the order.
  Nothing for the other packages: `ListenerRule` of `dispatcher` left with `#[Listener]` (its `AGENTS.md`).

## Tests

- `types/*Types.php` are fixtures of `assertType()` calls, never executed: `make phpstan` analyses them
  with the extension and reports every type that differs. The package has no `tests/`, so no Testo suite:
  Testo fails one without tests. Rejected: `PHPStan\Testing\TypeInferenceTestCase` (PHPUnit), a Testo test
  that runs PHPStan on the fixtures (a second run of what `make phpstan` checks).
- No mutation testing (`infect` of `check.yaml`): the code runs only inside PHPStan, and Infection loads a mutant
  only into the process of a test. Rejected: a Testo test that analyses the fixtures in its process. It builds
  the analysis from classes outside the API of PHPStan (`ContainerFactory`, `Analyser`, `PathRoutingParser`),
  repeats the CLI by hand (`Analyser::analyse()` does not tell `pathRoutingParser` which files are analysed,
  without it a fixture loses its function bodies and the test passes on any type) and cannot load
  `tools/phpstan.neon` (the strict rules are in the global Composer of the image). A tried run killed 49 mutants
  and found only what a review finds: a fixture for unpacked arguments, a redundant check in `MapReturnType`.
- A fixture function is named after what it checks, not after the function of the package: a namespaced `all()`
  would clash with the imported `Thesis\Async\all`.
- The fixtures of a rule are `types/*Errors.php`, next to those of the types: every error the rule must report
  is ignored by `@phpstan-ignore` on the line before it. PHPStan fails on an error that is not ignored
  and on an ignore that matches no error, so both a missed and an extra error fail `make phpstan`.
- `PhpParser\` lives inside `phpstan.phar`: `tools/composer-dependency-analyser.php` ignores it as unknown.
  The analyser does not read `types/`, it is outside the autoload. It also loads every class it meets
  to find its file, so `src/` uses no class of PHPStan that extends one of PhpParser (`InClassMethodNode`):
  the analyser fails on it. A rule takes the node of PhpParser (`ClassMethod`) and the class from `Scope`.
