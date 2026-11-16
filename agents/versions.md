# Versions

Read before touching the version of a package or a constraint on a monorepo package.
How a version is released (the checks, the tags, the workflow) is in `releases.md`, the changelogs
in `changelogs.md`, the repositories in `repositories.md`.

Every package has versions of its own. One series for all packages would put a package written a week ago
at 2.0.0, above the tags of the older packages whose names the monorepo reuses (`amqp` 1.0.2 and a branch `1.1.x`,
`exceptionally` 0.3.0, `formatter` 0.1.0, `nats` 0.5.0), and below 1.0 a break in one package would bump
the minor of all.

## Rules

- **A series per package.** The manifest declares the current series of the package: `version: '0.1'`.
  It is changed by hand, in the commit that starts the series (a break below 1.0, a new minor above).
  `sync` writes it into the generated `composer.json` of the package as `extra.branch-alias`
  (`dev-main` is `0.1.x-dev`), merged with the `extra` of the manifest (`phpstan-extension`), which may not set
  `branch-alias` itself. The root `composer.json` replaces every package with its series (`0.1.x-dev`),
  not `self.version`: the root is `dev-main`, which no `^0.Y` of an external dependency would accept.
- **Constraints from the series.** `'thesis/async' => '*'` is generated as the range of versions compatible
  with the series of the dependency: `^0.Y` below 1.0, `^X.0` above. A manifest may raise the lower bound
  (`^0.1.5`, `^2.1`) inside that range, `sync` fails outside it. A new minor of `amqp` 2.x keeps the range
  `^2.0` and changes no dependent. A new series of `async` 0.x changes the range in the `require` of every
  package that depends on it, and such a package starts a new series too: its old series stays installable
  with the old `async`. So a break cascades to the dependents, not to every package. A dependent above 1.0
  takes a new minor for it (`amqp` 2.1 on `async` 0.2): a user who calls `async` directly gets its break
  with a minor of `amqp`, which is the price of stable packages on dependencies below 1.0.
  A package that knows another one without requiring it (`phpstan-extension` knows the functions of `async`)
  is outside the cascade: a new series of `async` releases it too when its types change.
  The release refuses a forgotten new series of a dependent (`releases.md`).
- **One branch.** Only `main` of the monorepo is split, to `main` of every package, the default branch:
  a series lives on `main` until the next one starts. There are no maintenance branches yet: a fix goes
  to the current series, and the branches of older series are designed with the first backport that someone needs.
  A series started by mistake is undone by a commit while it is unreleased: it changes only the alias of `main`.
- **A new series is above every older version and branch of its name**: new packages start at 0.1,
  `amqp` at 2.0, `exceptionally` at 0.4, `formatter` at 0.2, `nats` at 0.6. The release refuses a version
  that breaks it, so the older tags and branches never collide with ours and stay as they are, with their history:
  Packagist keeps the old versions and the locks that point to their commits keep working.

Open:

- The tests run with the newest version of every dependency, never with the lowest one that a range allows.
  A job that installs every package alone with its lowest and highest dependencies is still to be added:
  it also catches a forgotten lower bound.

## Decisions

- Rejected: one series for all packages (`Config::$version`, the first design): new packages at 2.0,
  and a break of one bumps all of them below 1.0. A shared series above every older tag and branch would have
  to start at 1.2 and promise stability nobody gives. Shared series for groups of packages: two release
  mechanisms instead of one.
- Rejected: a tag in every package on every release (strict lockstep): empty releases of unchanged packages.
- Rejected: lower bounds rewritten by the release to the latest tags of the dependencies: a release would change
  the generated `composer.json` and would cascade a tag to every dependent package.
- Rejected: no `main` in split repositories, a series is a branch, as in the older repositories. Every review of it
  found a new way to lose commits or block the split: the last commits of a series had to be pushed to its branch
  from the history at every change of the series, the ranges of a dependent had to be checked against that branch
  by the split, a series undone by hand came back, and the default branch switched with every series.
  With `main` a series is one line of an alias until it is released.
- Rejected: maintenance branches now (`<name>/X.Y.x` split to `X.Y.x`): the most fragile part of every review.
  A pushed branch runs the workflow and the tooling of its own old commit, the alias of `main` collides
  with a branch of its series, the commit to start from is ambiguous (a split commit is shared by every commit
  that leaves the package as it is), and a backport across packages is copied once per branch. Below 1.0
  nobody has asked for one yet.
- Rejected: starting at 2.0 for every package (with old tags pushed by hand into the new repository):
  a package written a week ago would claim a maturity it does not have.
