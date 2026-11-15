# tools/monorepo

The monorepo tooling behind `bin/monorepo` (Symfony Console): `sync` and `github`.
Rejected: a scaffolding command, a new package is copied by hand after an existing one.

- `Manifest` and `Config` are the public format of `packages/*/package.php` and `monorepo.php`, the only `@api`.
  Keep them plain readonly DTOs with named arguments: humans and agents write them by hand. The rest is in `Internal\`.
- Every composer.json is generated entirely, from typed properties of `Manifest` and `Config`: a key without
  a property does not exist, so nothing is read back from the current file. Rejected: a raw array of
  composer.json keys merged last, it bypasses the model; a library modelling composer.json
  (symplify/composer-json-manipulator is abandoned), our DTOs are that model.
- Exceptions of a package to the quality tools are typed fields of `Manifest`, with paths relative to the package,
  so a renamed package or file does not leave a stale entry in `tools/*.php`: `optional` (a dev dependency used in
  `src/` behind a check) for composer-dependency-analyser, `FixerSkip` and `RectorSkip` with a required reason.
  `sync` throws on a skipped path that does not exist. The tools are installed globally and do not load our
  autoloader, so `sync` writes plain arrays to `tools/manifests.php` and their configs `require` it.
  Literals there are written the way PHP CS Fixer and Rector want (no extra `\\`, `::class`): the file is checked
  by them too. `optional` covers the whole `src/` of the package, not single files: precise paths are bookkeeping
  that only shields a forgotten check. PHPStan is not modelled: `@phpstan-ignore` in the code is precise
  and reports itself when it is no longer needed. A fixer skip excludes the whole file: the fixer cannot turn off
  one rule for one file.
- `Monorepo::load()` reads the manifests, `Generator` builds every derived file, `sync` writes the changed ones.
- No validator and no `check` command: a second source of truth. An error is whatever keeps `sync` from
  generating the files, thrown as `MonorepoException` (one line per problem, with a path and what to do)
  before anything is written. CI runs `make sync` and fails on any change in git.
- `Requirements` resolves the constraints of external dependencies, php and `Config::$platform` with composer/semver
  and throws with every conflict at once.
- Conventions (dependency direction, references between packages, `@api`/`@internal`, namespaces, `AGENTS.md`)
  are not checked: a check is added when a real problem shows up, not in advance, and preferably by running
  the final tool (composer, composer-dependency-analyser) rather than by reimplementing it.
  Rejected: layers, no cycles, a validator of code references against `require`.
- Generated JSON is compared by meaning, not by text: `composer normalize` owns the formatting of composer.json.
- `github` (`Internal\GitHub\Repositories`) brings `thesis-php/<dir>` to the state of ADR 0002: creates it, sets
  the settings that differ, creates or replaces the two rulesets. It compares only the keys it sends
  (`Json::canonical`): GitHub returns more and in its own order. CI runs it with the token of the app,
  locally `GITHUB_TOKEN=$(gh auth token) make github ARGS=--dry-run`.
- `github` throws on an existing repository without our rulesets: older packages of the organization share names
  with new ones. Taking one over is done by hand, by adding the rulesets. A name that GitHub redirects to a renamed
  repository (`full_name` differs) is free: the repository is created, which ends the redirect.
- The organization, the default branch and the id of the app are constants of `Repositories`, not of `Config`:
  they describe the infrastructure, not the packages, and nobody writes them by hand.
- HTTP goes through `file_get_contents()` with a stream context behind the `Api` interface: a few JSON requests
  do not need a client library. Tests use `FakeApi`.
