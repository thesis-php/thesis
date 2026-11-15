# 4. Every package has versions of its own

Status: accepted

## Context

ADR 0002 gives all packages one version series starting at 2.0, above the tags of the older packages whose names
the monorepo reuses (`amqp` 1.0.2 and a branch `1.1.x`, `exceptionally` 0.3.0, `formatter` 0.1.0, `nats` 0.5.0).
So a package written a week ago would come out as 2.0.0 and claim a maturity it does not have, and below 1.0,
where every minor version breaks compatibility, a break in one package would bump the minor of all.
No tag of the monorepo exists yet, so nothing published has to change.

## Decision

1. **A series per package.** The manifest declares the current series of the package: `version: '0.1'`.
   It is changed by hand, in the commit that starts the series (a break below 1.0, a new minor above).
   `sync` writes it into the generated `composer.json` of the package as `extra.branch-alias`
   (`dev-main` is `0.1.x-dev`), where the release reads it from the tree of a commit with `git cat-file`:
   a manifest of another commit cannot be run. `Config::$version` goes.
2. **Constraints from the series.** `'thesis/async' => '*'` is generated as the range of versions compatible
   with the series of the dependency: `^0.Y` below 1.0, `^X.0` above. A manifest may raise the lower bound
   (`^0.1.5`, `^2.1`) inside that range, `sync` fails outside it. A new minor of `amqp` 2.x keeps the range
   `^2.0` and changes no dependent. A new series of `async` 0.x changes the range in the `require` of every
   package that depends on it, and such a package starts a new series too: its old series stays installable
   with the old `async`. So a break cascades to the dependents, not to every package.
3. **One branch.** The split goes from `main` of the monorepo to `main` of every package, the default branch,
   as before, and a series lives on `main` until the next one starts. There are no maintenance branches yet:
   a fix goes to the current series, and the branches of older series are designed with the first backport
   that someone needs.
4. **A release is a commit.** Every package has a `CHANGELOG.md` (Keep a Changelog), and a release turns
   its `[Unreleased]` into `[0.1.4] - <date>` (or `[0.2.0-beta1]`). Several packages are released by one commit.
   A push of `main` releases the headings it added: a heading right under `[Unreleased]` whose version
   the changelog had not before the push. The package is released as it is at the head of the push.
   Nothing else is released on a push, so a release that failed is never retried with somebody else's
   later work: it is repaired by a re-run of its job or by `workflow_dispatch`, which releases the heading
   under `[Unreleased]` of a package at a given commit of `main`.
5. **A heading is released only if**
   - `[Unreleased]` of the package is empty: a listed change is not part of this version,
   - the version belongs to the series of the manifest and is higher than every tag of that series
     in the split repository, with or without `v` (composer reads them as one version),
   - every tag of that series or above in the split repository is at an ancestor of the split being released,
     and the repository has no branch of the series (`0.1.x`): what the split did not write is an older package
     of the same name, and a version or a `0.1.x-dev` of the series would collide with it,
   - the previous heading of the series, if any, is tagged: a version whose release failed and that a later
     heading followed would be lost,
   - the repository is a split under its own name (`Repositories::isSplit()`),
   - every dependency of `require` on a monorepo package that the previous release of the series has too
     has the same generated range (item 2), whatever its lower bound,
   - every lower bound of `require` on a monorepo package is met by an existing stable tag or by a heading
     of the same release, stable for a stable release: a stable version that needs a beta cannot be installed
     without `minimum-stability`. `require-dev` is not checked, it is not installed with the package.

   A tag of the version that exists already at the split being released counts as released, and the release
   goes on with the steps after it: a re-run continues a release that failed halfway. A tag at any other commit
   fails. Every heading is decided before anything is pushed. One that fails stops itself and the headings
   of the same release whose lower bounds need it, not the others.
6. **The steps, per package, dependencies first**: push the split of the released commit to `main` of the split
   repository if it is not there yet (a fast-forward, never forced: the release does not wait for the split job),
   the lightweight tag `vX.Y.Z` at it, then its GitHub Release with `target_commitish` set to that commit
   (GitHub tags the default branch otherwise). A step that exists already counts as made, a tag compared
   by the commit it points to: two runs may release the same heading at once.
7. **Releases on GitHub, in the split repository.** The body is the section of the changelog, `prerelease`
   is set for `-alphaN`, `-betaN`, `-RCN`, and `make_latest` is always sent (GitHub takes `true` otherwise),
   `true` only for the highest stable version of the package. A user subscribes to the releases
   of the repository of the package (Watch, `releases.atom`), and Dependabot and Renovate take their notes
   from there: it is the source of the package on Packagist.
8. **The workflow.** The release is a job of the workflow of the checks and `needs` them. The checks
   are cancelled by a newer run only for a pull request, and the release has no concurrency group:
   a pending run of a group is replaced by a newer one. `workflow_dispatch` takes a commit of `main`,
   runs the checks on it and then the release. The release needs the history of the monorepo (`fetch-depth: 0`).
   The split job stays as it is, CI no longer runs on tags.
9. **A new series is above every older version and branch of its name**: new packages start at 0.1,
   `amqp` at 2.0, `exceptionally` at 0.4, `formatter` at 0.2, `nats` at 0.6. Item 5 refuses a release
   that breaks it, so the older tags and branches never collide with ours and stay as they are,
   with their history: Packagist keeps the old versions and the locks that point to their commits keep working.
10. **Taking over an older repository** is done once, by hand: its own rulesets that would stop the app are
    deleted or let the app through (`release-branches` of `amqp` and `nats` forbids creating `*.x`), its open
    issues are moved or closed (`github` turns the issues off), branches that are still needed are merged
    or moved to the monorepo, and the rulesets of the split are added. Nothing is force-pushed: the split
    creates `main` where it is missing and fast-forwards it where it pushed already (`amqp`, `exceptionally`).
    The read-only ruleset covers every branch and tag, so the older series end there: they are installable
    as before and get no more commits or tags. The split pushes nothing to a repository without the rulesets
    of the split or to a name that redirects to a renamed one (`Repositories::isSplit()`).

The split, the read-only repositories and the app of ADR 0002 stay as they are.

## Consequences

- A series started by mistake is undone by a commit while it is unreleased: it changes only the alias of `main`.
- A new series of a dependency blocks the releases of its dependents until it is released itself: their old
  series no longer has its range, their new one needs its tag. So a new series of `async` or `time` is released
  soon after it starts, and the dependents are released before it starts if they have something to release.
- A forgotten new series of a dependent fails its release, not the push: `main` of a package may hold commits
  that need a newer series of a dependency than its alias says, until the next release.
- A package is tested with the heads of its dependencies, so a release may rely on a fix of a dependency
  that is not released yet. The lower bounds of item 5 do not see it: the job of ADR 0002 that installs
  every package alone with its lowest dependencies does, and it is still to be added.
- A version is chosen per package and release, and its changes are written in its own changelog,
  where a user of the split repository reads them.
- Stable 0.x versions need no `minimum-stability`, a pre-release is made only where it is needed.
- The root `composer.json` replaces every package with its series (`0.1.x-dev`), not `self.version`:
  the root is `dev-main`, which no `^0.Y` of an external dependency would accept.
- The release assumes the package is registered on Packagist (ADR 0002, item 4), which is not automated yet.

## Rejected

- **One series for all packages** (ADR 0002): new packages at 2.0, and a break of one bumps all of them below 1.0.
  A shared series above every older tag and branch would have to start at 1.2 and promise stability nobody gives.
- **Shared series for groups of packages**: two release mechanisms instead of one.
- **No `main` in split repositories, a series is a branch**, as in the older repositories. Every review of it
  found a new way to lose commits or block the split: the last commits of a series had to be pushed to its branch
  from the history at every change of the series, the ranges of a dependent had to be checked against that branch
  by the split, a series undone by hand came back, and the default branch switched with every series.
  With `main` a series is one line of an alias until it is released.
- **Maintenance branches now** (`<name>/X.Y.x` split to `X.Y.x`): the most fragile part of every review.
  A pushed branch runs the workflow and the tooling of its own old commit, the alias of `main` collides
  with a branch of its series, the commit to start from is ambiguous (a split commit is shared by every commit
  that leaves the package as it is), and a backport across packages is copied once per branch. Below 1.0
  nobody has asked for one yet.
- **Releasing the top heading of every package on every push**: a release that failed would be retried
  at the head of the next push, with its work in the tag, and every push would ask GitHub about every package.
- **The release after the split job**: the split job fails when any package fails, and a run of it that a newer
  one replaced ends as cancelled, so the release would be blocked or skipped. Pushing the split itself is a
  fast-forward of the same deterministic commit.
- **Tags of the monorepo** (`<name>/vX.Y.Z`): nothing needs them without maintenance branches.
- **Releasing the commit that added the heading** (`git blame`): a fix on the same day does not change the line,
  and a later edit of the line moves it away from the tag.
- **Tags pushed by hand**: the version would be written twice, in the tag and in the changelog, and a tag
  is public before anything checks it. GitHub also starts no workflow for a push of more than three tags.
- **Leaving the older branches out of the read-only ruleset**: an exception per package in the manifests
  for series that nobody maintains.
- **Changesets** (a note per change, collected into a release): a second place for what `[Unreleased]` holds.
- **Releases in the monorepo**: one feed of every package retelling their changelogs, and not the repository
  that Packagist, Dependabot and the users of a package look at.
- **Importing the history of an older repository into the monorepo** to continue its branches: the split drops
  signatures, so the commits of GitHub's web interface get other ids anyway, and the monorepo gets foreign history.
