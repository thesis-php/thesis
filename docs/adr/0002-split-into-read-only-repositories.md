# 2. Packages are split into read-only repositories

Status: accepted

## Context

The monorepo `thesis-php/thesis` holds every package in `packages/<name>/`, while composer installs `thesis/<name>`
from a repository of its own. Those repositories must be exact copies of their directories with the history
of them: a change made there would be lost on the next split or would diverge from the monorepo. Their settings
(description, disabled features, protection) must be the same everywhere and must not need hand work
for a new package. The organization is on the free plan of GitHub.

## Decision

1. **Split.** On every push of `main`, `*.*.x` or a `v*` tag, CI splits each `packages/<name>` with splitsh-lite
   and pushes it to the same ref of `thesis-php/<name>` (`tools/split`, `make split`). The split keeps the history
   of the directory and is deterministic, so the pushes are never forced.
2. **Push by an app.** A GitHub App of the organization pushes the splits and manages the repositories:
   its id and key are `vars.BOT_CLIENT_ID` and `secrets.BOT_PRIVATE_KEY` of the monorepo.
3. **Read-only.** A split repository has pull requests, issues, wiki, projects and discussions disabled.
   Two rulesets, on all branches and on all tags, forbid creation, update, deletion and force pushes to everyone
   but the app, the owners of the organization included. Rulesets of a repository are free for public repositories,
   so no paid plan is needed.
4. **Settings come from the manifests.** `bin/monorepo github` brings every `thesis-php/<name>` to the state
   derived from `packages/<name>/package.php` and `monorepo.php`: it creates the missing repository, sets
   the description, the homepage (`packages/<name>` in the monorepo), the disabled features and the rulesets,
   and registers the package on Packagist. GitHub itself is the state, the command is idempotent and deletes nothing.
   It refuses a repository that exists without the rulesets of the split: the organization holds older packages
   under the same names (`thesis-php/time`), and taking one over is a decision, not a side effect of a push.
5. **One version, tags only where something changed.** All packages share the version series of `Config::$version`.
   A tag `vX.Y.Z` of the monorepo goes to a package only if `packages/<name>` changed since the previous tag
   of the same series. The first tag of a series (`v0.2.0`) goes to every package, so that `^0.2` resolves for all.
6. **Explicit lower bounds.** A dependency on a monorepo package is `'*'` by default, which the generator writes
   as `Config::$version`. A manifest may give a narrower constraint (`'thesis/io' => '^0.1.3'`) when it starts
   using an API that appeared later in the series. `sync` fails when such a constraint is outside
   `Config::$version`, the release fails when no existing or new tag of the dependency satisfies it.

## Consequences

- A contribution is a pull request to the monorepo only. A split repository says so through its homepage.
- CI runs `bin/monorepo github` before the split, so a new package gets its repository with its first push.
  A package named like an older repository fails CI until that repository is renamed, deleted or taken over.
- The root lock tests one combination of dependencies. Installing every package alone with the lowest
  and the highest allowed versions is a separate job, worth it once there are tags to resolve.
- A forgotten lower bound breaks only the users who keep an older dependency. That job catches it.

## Rejected

- **`git subtree split`**: walks the whole history on every run. **Actions that copy files into a new commit**:
  no history, no deterministic commits, so every split is a new commit.
- **A workflow that closes pull requests**: GitHub can disable them (`has_pull_requests`).
- **An archived repository as read-only**: the app could not push to it either.
- **Deploy keys or a personal token**: a key per repository is a secret per package, a token belongs to a person,
  neither can create repositories for the organization.
- **Terraform**: a state of its own and a second description of the packages. **The settings app of Probot**:
  a configuration file in each repository and weak support of rulesets.
- **A tag in every package on every release** (strict lockstep): empty releases of unchanged packages.
  **Independent versions per package**: a changelog and constraints of each package kept by hand.
- **Lower bounds rewritten by the release** to the latest tags of the dependencies: a release would change
  the generated `composer.json` and would cascade a tag to every dependent package.
