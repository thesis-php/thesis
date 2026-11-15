# tools/monorepo

The monorepo tooling behind `bin/monorepo` (Symfony Console): `sync`.
Rejected: a scaffolding command, a new package is copied by hand after an existing one.

- `Manifest` and `Config` are the public format of `packages/*/package.php` and `monorepo.php`, the only `@api`.
  Keep them plain readonly DTOs with named arguments: humans and agents write them by hand. The rest is in `Internal\`.
- Every composer.json is generated entirely, from typed properties of `Manifest` and `Config`: a key without
  a property does not exist, so nothing is read back from the current file. Rejected: a raw array of
  composer.json keys merged last, it bypasses the model; a library modelling composer.json
  (symplify/composer-json-manipulator is abandoned), our DTOs are that model.
- `Monorepo::load()` reads the manifests, `Generator` builds every derived file, `sync` writes the changed ones.
- No validator and no `check` command: a second source of truth. An error is whatever keeps `sync` from
  generating the files, thrown as `MonorepoException` (one line per problem, with a path and what to do)
  before anything is written. CI runs `make sync` and fails on any change in git.
- `Requirements` resolves the constraints of external dependencies and php with composer/semver
  and throws with every conflict at once.
- Conventions (dependency direction, references between packages, `@api`/`@internal`, namespaces, `AGENTS.md`)
  are not checked: a check is added when a real problem shows up, not in advance, and preferably by running
  the final tool (composer, composer-dependency-analyser) rather than by reimplementing it.
  Rejected: layers, no cycles, a validator of code references against `require`.
- Generated JSON is compared by meaning, not by text: `composer normalize` owns the formatting of composer.json.
