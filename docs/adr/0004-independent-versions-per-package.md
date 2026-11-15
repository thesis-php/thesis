# 4. Every package has versions of its own

Status: accepted

## Context

ADR 0002 gives all packages one version series starting at 2.0, above the tags of the older packages whose names
the monorepo reuses (`amqp` 1.0.2 and a branch `1.1.x`, `exceptionally` 0.3.0, `nats` 0.5.0). So a package
written a week ago would come out as 2.0.0 and claim a maturity it does not have, and below 1.0, where every minor
version breaks compatibility, a break in one package would bump the minor of all. No tag of the monorepo
exists yet, so nothing published has to change.

## Decision

1. **A series per package.** The manifest declares the current series of the package: `version: '0.1'`.
   It is changed by hand, in the commit that starts the series (a break below 1.0, a new minor above).
   `sync` writes it into the generated `composer.json` of the package as `extra.branch-alias`
   (`dev-main` is `0.1.x-dev`), where the release reads it from the tree of a commit with `git cat-file`:
   a manifest of another commit cannot be run. `Config::$version` goes.
2. **Constraints from the series.** `'thesis/async' => '*'` is generated as the range of versions compatible
   with the series of the dependency: `^0.Y` below 1.0, `^X.0` above. A manifest may raise the lower bound
   (`^0.1.5`, `^2.1`) inside that range, `sync` fails outside it. A new minor of `amqp` 2.x changes no dependent,
   a new series of `async` 0.x changes the range in the `require` of every package that depends on it, and such
   a package starts a new series too: its old series stays installable with the old `async`.
   So a break cascades to the dependents, not to every package.
3. **Branches.** The split goes from `main` of the monorepo to `main` of every package, the default branch,
   as before. A fix of an older series is made in the monorepo branch `<name>/X.Y.x`, created from the tag
   `<name>/vX.Y.Z` of the last release of that series. It is split only to `X.Y.x` of that package, which composer
   reads as `X.Y.x-dev`, and fails if the manifest there has another series. The other packages of that branch
   are tested, not published. `github` runs for `main` only: a maintenance branch holds older manifests.
4. **A release is a commit.** Every package has a `CHANGELOG.md` (Keep a Changelog), and a release turns
   its `[Unreleased]` into `[0.1.4] - <date>` (or `[0.2.0-beta1]`). Several packages are released by one commit.
   The release runs on a push of `main` or `<name>/X.Y.x`, after the checks and after the split of the same push,
   and is never cancelled or replaced by a newer run. It looks at the heading right under `[Unreleased]`
   of every package of the branch (of `<name>` only on `<name>/X.Y.x`) and releases the package as it is
   at the head of the push: a change that came after the release commit without a line in the changelog
   (a fix of a red check) is released with it, a change with a line has to wait for the next heading.
5. **What a heading means is decided in this order**, so that a run is repeated safely and nothing is lost
   in silence:
   1. Its version is tagged in the split repository (with or without `v`: composer reads them as one version).
      At the split of a commit of this monorepo the remaining steps of item 6 are ensured, with no checks:
      a run that failed halfway goes on. At any other commit, a version of a lower series is skipped
      (it was released before the monorepo, `[0.3.0]` of `exceptionally`), anything else fails.
   2. It is not tagged. The release fails unless
      - `[Unreleased]` of the package is empty: a listed change is not part of this version,
      - the version belongs to the series of the branch and is higher than every tag of that series,
      - the previous heading of that series, if any, is tagged: a version whose run failed and that a later
        heading followed would be lost,
      - the split of the head is in the branch of the split repository already: the split ran, and pushed
        to the repository of this name, not to one that it redirects to (`Repositories::isSplit()`),
      - every dependency of `require` on a monorepo package that the previous release of the series has too
        allows the same series of it (item 2), whatever its lower bound: a new series of a dependency
        needs a new series here,
      - every lower bound of `require` on a monorepo package is met by an existing tag or one of the same
        release, stable for a stable release: a stable version that needs a beta cannot be installed without
        `minimum-stability`. `require-dev` is not checked, it is not installed with the package.
6. **Every step is ensured**: per package, in this order, the tag `vX.Y.Z` of the split of the head
   in the split repository, its GitHub Release, the tag `<name>/vX.Y.Z` of the monorepo. One that exists already
   at the same commit counts as made: two runs may release the same heading at once. The tags of the monorepo
   only record what was released: a ruleset, kept by `github`, lets only the app create `*/v*`.
7. **Releases on GitHub, in the split repository.** The body is the section of the changelog, `prerelease`
   is set for `-alphaN`, `-betaN`, `-RCN`, and `make_latest` is always sent (GitHub takes `true` otherwise):
   `true` only for the highest stable version of the package, so a fix of an older series does not become
   the latest. A user subscribes to the releases of the repository of the package (Watch, `releases.atom`),
   and Dependabot and Renovate take their notes from there: it is the source of the package on Packagist.
8. **A new series is above every older version and branch of its name**: new packages start at 0.1,
   `amqp` at 2.0, `exceptionally` at 0.4, `nats` at 0.6. So the older tags and branches never collide
   with ours and stay as they are, with their history: Packagist keeps the old versions and the locks
   that point to their commits keep working. A new repository of an older name does not get the older tags:
   the old repository keeps them under its new name.
9. **Taking over an older repository** is done once, by hand: its own rulesets that would stop the app are
   deleted or let the app through (`release-branches` of `amqp` and `nats` forbids creating `*.x`), its open
   issues are moved or closed (`github` turns the issues off), the rulesets of the split are added.
   The next split creates `main`, nothing is force-pushed: the older repositories have no `main`.
   The split pushes nothing to a repository without the rulesets of the split or to a name that redirects
   to a renamed one (`Repositories::isSplit()`).

The split, the read-only repositories and the app of ADR 0002 stay as they are.

## Consequences

- CI splits on a push of `main` and of `*/*.*.x`, a pushed tag triggers nothing. The release needs the history
  of the monorepo (`fetch-depth: 0`) and a concurrency group of its own per run.
- A series started by mistake is undone by a commit: it changes only the alias of `main` until it is released.
- A forgotten new series of a dependent fails its release, not the push: `main` of a package may hold commits
  that need a newer series of a dependency than its alias says, until the next release.
- A version is chosen per package and release, and its changes are written in its own changelog,
  where a user of the split repository reads them.
- Stable 0.x versions need no `minimum-stability`, a pre-release is made only where it is needed.
- A backport that needs a fix of a dependency takes a branch per package (`async/0.1.x`, `io/0.1.x`).
- The root `composer.json` replaces every package with its series (`0.1.x-dev`), not `self.version`:
  the root is `dev-main`, which no `^0.Y` of an external dependency would accept.
- The tests run with the newest version of every package, never with the lowest one that a range allows.
  The job of ADR 0002 that installs every package alone with its lowest dependencies is still to be added.

## Rejected

- **One series for all packages** (ADR 0002): new packages at 2.0, and a break of one bumps all of them below 1.0.
  A shared series above every older tag and branch would have to start at 1.2 and promise stability nobody gives.
- **Shared series for groups of packages**: two release mechanisms instead of one.
- **No `main` in split repositories, a series is a branch**, as in the older repositories. Every review of it
  found a new way to lose commits or block the split: the last commits of a series had to be pushed to its branch
  from the history at every change of the series, the ranges of a dependent had to be checked against that branch
  by the split, a series undone by hand came back, and the default branch switched with every series.
  With `main` a series is one line of an alias until it is released.
- **Releasing the commit that added the heading** (`git blame`): a fix on the same day does not change the line,
  and a later edit of the line moves it away from the tag.
- **Tags pushed by hand**: the version would be written twice, in the tag and in the changelog, and a tag
  is public before anything checks it. GitHub also starts no workflow for a push of more than three tags.
- **Changesets** (a note per change, collected into a release): a second place for what `[Unreleased]` holds.
- **Releases in the monorepo**: one feed of every package retelling their changelogs, and not the repository
  that Packagist, Dependabot and the users of a package look at.
- **Importing the history of an older repository into the monorepo** to continue its branches: the split drops
  signatures, so the commits of GitHub's web interface get other ids anyway, and the monorepo gets foreign history.
