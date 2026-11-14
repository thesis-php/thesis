# tools/monorepo

The monorepo tooling behind `bin/monorepo` (Symfony Console): `sync`, `check`, `new`.

- `Manifest`, `Config` and `Layer` are the public format of `packages/*/package.php` and `monorepo.php`, the only `@api`.
  Keep them plain readonly DTOs with named arguments: humans and agents write them by hand. The rest is in `Internal\`.
- `Monorepo::load()` reads the manifests, `Validator` reports problems as a list of messages
  (one message per problem, with a path and what to do), `Generator` builds every derived file.
- `sync` writes nothing while there are problems. `check` never writes.
- Generated JSON is compared by meaning, not by text: `composer normalize` owns the formatting of composer.json.
- A new rule of the monorepo goes to `Validator` and to the root `AGENTS.md` together.
