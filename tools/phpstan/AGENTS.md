# Monorepo PHPStan rules

The root `phpstan.neon` loads these rules for contributors. The published `thesis/phpstan-extension` describes
the types and contracts of packages for their users.

- Each rule checks package sources and a second copy of the package layout under `fixtures/`. Both registrations
  use paths relative to the repository so the checks also work when PHPStan runs from a package directory.
- `make phpstan` analyses the fixtures. Mark each expected diagnostic with its identifier in a `@phpstan-ignore`.
  A missing diagnostic fails the analysis as an unmatched ignore.
- Check API tags on declarations in the file's namespace, including namespace constants and traits with no user.
  File nodes let the rule inspect declarations once without traversing method bodies or relying on trait usage.
