# thesis/phpstan-extension

The PHPStan extension of all thesis packages: the types that their PHPDoc cannot express, and rules for what
PHP cannot check (an attribute on a wrong method). How to use it is in `README.md`.

This file is for agents changing this package. It is not loaded while an agent works in another package,
so how to *use* this package belongs in `README.md` or in a skill under `skills/<skill-name>/SKILL.md`.

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
- A rule reports an error at the line of what is wrong (the attribute, not the method), with an identifier
  `thesis.<camelCase>`, so a user ignores exactly it. A rule checks only what the package cannot check itself
  or checks only at runtime: `ListenerRule` repeats the runtime check of a method that is not public to report it
  without running, and adds the one `listenersOf()` cannot do (an abstract method, the mark is not inherited).

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
  Of the other packages only `dispatcher` needs a rule (`ListenerRule`).

## Tests

- `types/*Types.php` are fixtures of `assertType()` calls, never executed: `make phpstan` analyses them
  with the extension and reports every type that differs. The package has no `tests/`, so no Testo suite:
  Testo fails one without tests. Rejected: `PHPStan\Testing\TypeInferenceTestCase` (PHPUnit), a Testo test
  that runs PHPStan on the fixtures (a second run of what `make phpstan` checks).
- A fixture function is named after what it checks, not after the function of the package: a namespaced `all()`
  would clash with the imported `Thesis\Async\all`.
- `types/*Errors.php` are fixtures of a rule, next to those of the types: every error the rule must report
  is ignored by `@phpstan-ignore` on the line before it. PHPStan fails on an error that is not ignored
  and on an ignore that matches no error, so both a missed and an extra error fail `make phpstan`.
- `PhpParser\` lives inside `phpstan.phar`: `tools/composer-dependency-analyser.php` ignores it as unknown.
  The analyser does not read `types/`, it is outside the autoload. It also loads every class it meets
  to find its file, so `src/` uses no class of PHPStan that extends one of PhpParser (`InClassMethodNode`):
  the analyser fails on it. A rule takes the node of PhpParser (`ClassMethod`) and the class from `Scope`.
