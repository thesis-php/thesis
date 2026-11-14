# Roadmap

Plans and decisions of the monorepo that are not implemented yet, and alternatives that were rejected.
Take the next unchecked item, implement it, move what became a rule to `AGENTS.md` and check the item off.

## Next

### Test services

Packages like `amqp`, `nats`, `pgmq` need servers in their tests.

- [ ] A package declares its services in `packages/<name>/compose.yaml`, without the `php` service.
  Their configs live next to it, in `packages/<name>/docker/`.
- [ ] The root `compose.yaml` is written by hand (`php` service) and includes the generated `compose.packages.yaml`,
  which includes every `packages/*/compose.yaml`: Compose `include` does not support globs.
- [ ] Every service of a package has `profiles: [<name>]`. `make test P=<name>` runs
  `docker compose --profile <name> up --detach --wait` before the tests, `make test` runs all profiles.
- [ ] `Manifest::$testServices`: profiles of other packages the tests need. A shared server has one owner
  (Postgres belongs to `pgmq`, `message-bus` and `orm` list it), like a dependency has one constraint.
- [ ] `bin/monorepo check` validates: a service name starts with the package name (`amqp-rabbitmq`),
  it has the package profile and a `healthcheck` (`up --wait` relies on it), it publishes no ports
  (tests run inside the Compose network, ports for a GUI go to the ignored `compose.override.yaml`),
  every profile in `testServices` exists.
- [ ] Tests read a DSN as `getenv('THESIS_NATS_DSN') ?: 'nats://nats-1:4222'`: the default works inside Compose,
  the variable is only for other environments. No environment plumbing into the `php` service.
- Later: toxiproxy services for network faults (reconnects, timeouts) instead of stopping containers from tests.

### CI in three levels

`make check` on the whole repository stops scaling at dozens of packages.

- [ ] Global job, always: `monorepo-check`, fixer and rector checks, phpstan on the whole repository
  with its result cache in `actions/cache` (a change in `async` can break types in `nats`).
- [ ] `bin/monorepo affected --base=<ref>` prints a JSON list for `strategy.matrix`: packages with changed files
  plus their transitive dependents through `uses`. A change outside `packages/` (`composer.lock`, `monorepo.php`,
  `tools/`, configs) affects every package. Each matrix job runs `make test P=<name>` with the shared vendor,
  so CI and local runs are the same, services included.
- [ ] Isolation job, for affected packages in a PR and for every package nightly: copy `packages/<name>` to a temporary
  directory, point path repositories to its siblings, `composer update --prefer-lowest` and then a regular update,
  run its tests and composer-dependency-analyser on its own `composer.json`. This catches what the shared vendor hides:
  an external dependency missing in the manifest but installed by another package, and wrong lower bounds.
- [ ] Mutation testing (`make infect P=<name>`) only for packages whose own code changed.

### Release and split

- [ ] Pushing a tag `v0.x.y` splits every `packages/<name>` (splitsh-lite) to a read-only `thesis/<name>` repository
  with the same tag. Pull requests go to the monorepo only.
- [ ] One version for all packages: `Config::$version` is the constraint packages require each other with,
  bump it on a release. Independent versions are not worth it before 1.0.
- [ ] `make sync` generates `packages/<name>/.gitattributes`: `export-ignore` for what users of the split package
  do not need (`tests/`, `AGENTS.md`, `package.php`, ...). Decide the exact list when the split is built.

## Migrating packages from the old repositories

The old packages are separate repositories (locally `~/Projects/thesis/<name>`), mostly built on amphp.

- Import with history: `git filter-repo --to-subdirectory-filter packages/<name>` in a clone, then merge it here
  with `--allow-unrelated-histories`. Then replace its composer.json with `package.php` and run `make sync`.
- Start from the lowest layers (`primitive`, `runtime`, `io`), move protocols as they are rethought.
- Split an old `CLAUDE.md`: invariants for those who change the package go to its `AGENTS.md`,
  rules for those who use it go to `README.md` or a skill in `packages/<name>/skills/` (users of `async` must not
  write amphp-style code, and they never see `packages/async/AGENTS.md`).
- Several old packages use the bare `Thesis\` namespace (`dic`, `mapper`, `time-span`, `headers`, `transaction`, ...),
  and `bytereader` and `byte-reader` both claim `Thesis\ByteReader\`. The validator requires a unique sub-namespace,
  so each needs a namespace decision before the move.
- A package is rewritten on revolt/event-loop, not wrapped around amphp: the point is a new ecosystem,
  not a migration of the old one. Interop with amphp is an optional bridge (`ampCancellable()` of `async`).

## Rejected

- **A vendor directory per package** (path repositories everywhere): slow installs, versions drifting between packages,
  fragile path repositories. One vendor and one lock instead, with the isolation job covering what it hides.
- **JSON manifests** (hand-written package composer.json files merged by a tool): PHP manifests returning readonly DTOs
  get types, autocompletion and static analysis. composer.json files are generated for publishing only.
- **CLAUDE.md files**, including one-line `@AGENTS.md` shims: the team does not use Claude only, and Claude Code reads
  `AGENTS.md` by itself while the project has no `CLAUDE.md` (verified, nested files included).
- **GitHub Actions `services:`** for test servers: a static list per workflow, and CI would differ from local runs.
  The same Compose profiles run everywhere.
- **Hand-written `testo.php` with loops over packages**: it is generated as a flat list like every other derived file.
- **Tool configs in the root**: they live in `tools/`, the root keeps only what tools find by name
  (`composer.json`, `compose.yaml`, `testo.php`, `skills.json`) and the monorepo's own `AGENTS.md` and `monorepo.php`.
