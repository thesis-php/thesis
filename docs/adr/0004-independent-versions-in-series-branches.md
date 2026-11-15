# 4. Every package has versions of its own, a series is a branch

Status: accepted

## Context

ADR 0002 gives all packages one version series starting at 2.0, above the tags of the older packages whose names
the monorepo reuses (`amqp` 1.0.2 and a branch `1.1.x`, `exceptionally` 0.3.0, `time` 0.2.3, `nats` 0.5.0).
So a package written a week ago would come out as 2.0.0 and claim a maturity it does not have, and below 1.0,
where every minor version breaks compatibility, a break in one package would bump the minor of all.
The older repositories had no `main`: a series lived in a branch of its own (`1.0.x`, `0.5.x`), and the default
branch was one of them. No tag of the monorepo exists yet, so nothing published has to change.

## Decision

1. **A series per package.** The manifest declares the current series of the package: `version: '0.1'`.
   It is changed by hand, in the commit that starts the series (a break below 1.0, a new minor above).
   `sync` writes it into the generated `composer.json` of the package as `extra.thesis.series`, where the split
   and the release read it from the tree of a commit with `git cat-file`: a manifest of another commit cannot be
   run, and the key `version` of composer would hide every tag that differs from it. `Config::$version` goes.
2. **Constraints from the series.** `'thesis/async' => '*'` is generated as the range of versions compatible
   with the series of the dependency: `^0.Y` below 1.0, `^X.0` above. A manifest may raise the lower bound
   (`^0.1.5`, `^2.1`) inside that range, `sync` fails outside it. A new minor of `amqp` 2.x changes no dependent,
   a new series of `async` 0.x changes the range in the `require` of every package that depends on it, and such
   a package starts a new series in the same commit: its old series stays installable with the old `async`,
   and a series branch must not get commits that need another one. So a break cascades to the dependents,
   not to every package. The split enforces it: before it pushes to an existing series branch, it compares
   the ranges of `require` on monorepo packages with those at the head of that branch, and refuses the push
   of a package whose range changed. A lower bound that rises within its range is not a change.
3. **A series is a branch, a split repository has no `main`.** The monorepo develops on `main`. Its split goes,
   for every package, to the branch `X.Y.x` of the series that the package has in the head of the push.
   The split also walks the first-parent history from the head, and at every change of the series it pushes
   the last commit of the previous series to that branch, until it reaches a branch that has it already:
   a push may bring several changes of the series, and a run of the split may be skipped when a newer push
   is queued. A finished branch then stays where it is. Composer reads `0.1.x` as `0.1.x-dev`,
   no `branch-alias` is needed.
4. **`github` runs for `main` only, in two steps**: before the split it creates the repositories and brings
   their settings and rulesets (the split pushes only to a repository with them), after the split it sets
   the default branch to the series of `main`, which exists only then. A maintenance branch holds older manifests,
   which must not change the settings.
5. **Maintenance in a branch of one package.** A fix of an older series is made in the monorepo branch
   `<name>/X.Y.x`, created from the last commit of that series on `main`. It is split only to `X.Y.x` of that
   package and fails if the manifest there has another series. The split is deterministic, so the branch continues
   by a fast-forward. The other packages of that branch are tested, not published.
6. **A release is a commit.** Every package has a `CHANGELOG.md` (Keep a Changelog), and a release turns
   its `[Unreleased]` into `[0.1.4] - <date>` (or `[0.2.0-beta1]`). Several packages are released by one commit.
   The release runs on a push of `main` or `<name>/X.Y.x`, in the workflow of the checks, after them (`needs`),
   and that workflow cancels no run of these branches. It takes the heading right under `[Unreleased]`
   of every package of the branch (of `<name>` only on `<name>/X.Y.x`). A heading outside the series
   of the branch is one released before the monorepo (`[0.3.0]` of `exceptionally`) and is skipped.
   What is released is the commit that added the heading (`git blame` of its line), not the head.
   Before any push the release fails unless
   - the version is higher than every tag of its series in the split repository, with or without `v`:
     composer reads `0.3.0` and `v0.3.0` as one version,
   - the package at the head is the package of that commit: a later change that came in the same push
     or a fix of a red check was not released yet, and the checks ran on the head only. The heading is then
     moved to a new commit (its date updated), which is released instead,
   - every lower bound of `require` on a monorepo package is met by an existing tag or one of the same release,
     stable for a stable release: a stable version that needs a beta cannot be installed without
     `minimum-stability`. `require-dev` is not checked, it is not installed with the package.
7. **Every step of a release is ensured, so a run that failed halfway is run again.** Per package, in this order:
   the tag `vX.Y.Z` in the split repository, its GitHub Release, the tag `<name>/vX.Y.Z` of the monorepo.
   A tag of the split repository at the split of the released commit is kept and the missing steps after it
   are made, a tag at any other commit fails the release. The tags of the monorepo only record what was released:
   a ruleset, kept by `github`, lets only the app create `*/v*`.
8. **Releases on GitHub, in the split repository.** The body is the section of the changelog, `prerelease`
   is set for `-alphaN`, `-betaN`, `-RCN`, `make_latest` only for the highest stable version of the package:
   a fix of an older series must not become the latest. A user subscribes to the releases of the repository
   of the package (Watch, `releases.atom`), and Dependabot and Renovate take their notes from there:
   it is the source of the package on Packagist.
9. **A new series is above every older version and branch of its name**: new packages start at 0.1, `amqp` at 2.0,
   `exceptionally` at 0.4, `time` at 0.3, `nats` at 0.6. So the older tags and branches never collide
   with ours and stay as they are, with their history: Packagist keeps the old versions and the locks
   that point to their commits keep working.
10. **Taking over an older repository** is done once, by hand: its own rulesets that would stop the app are
    deleted or let the app through (`release-branches` of `amqp` and `nats` forbids creating `*.x`), its open
    issues are moved or closed (`github` turns the issues off), the rulesets of the split are added. The next split
    creates the branch of the series, nothing is force-pushed. The split pushes nothing to a repository without
    the rulesets of the split or to a name that redirects to a renamed one (`Repositories::isSplit()`).
    A new repository that takes an older name gets the older tags from the old one as soon as it is created,
    with the ruleset of tags turned off for it: until then the locks that point to them are broken.

The split, the read-only repositories and the app of ADR 0002 stay as they are.

## Consequences

- CI splits on a push of `main` and of `*/*.*.x`, a pushed tag triggers nothing.
- The default branch of a repository changes with every new series.
- The `main` branches that the split has already pushed (`async`, `io`, `net`, `time`, `testo-plugin`,
  `phpstan-extension`, and `amqp`, `exceptionally` where it created one next to the older branches) are deleted
  by hand after the first push of a series branch, with the rulesets turned off for it: they bind the owners too,
  and `github` deletes nothing.
- `thesis-php/time` exists already without the tags of `time-span`: they are pushed into it now.
- A series started by mistake leaves its branch, a `X.Y.x-dev` on Packagist and the default branch switched:
  undone by hand.
- A forgotten new series of a dependent fails the split of `main` until it is added, so the series branch
  of the dependent never gets a commit that needs the new series of its dependency.
- A version is chosen per package and release, and its changes are written in its own changelog,
  where a user of the split repository reads them.
- Stable 0.x versions need no `minimum-stability`, a pre-release is made only where it is needed.
- A backport that needs a fix of a dependency takes a branch per package (`async/0.1.x`, `io/0.1.x`).
- The root `composer.json` replaces every package with its series (`0.1.x-dev`), not `self.version`:
  the root is `dev-main`, which no `^0.Y` of an external dependency would accept.
- The tests run with the newest version of every package, never with the lowest one that a generated range
  allows: the job of ADR 0002 that installs every package alone with its lowest dependencies covers it.

## Rejected

- **One series for all packages** (ADR 0002): new packages at 2.0, and a break of one bumps all of them below 1.0.
  A shared series above every older tag and branch would have to start at 1.2 and promise stability nobody gives.
- **Shared series for groups of packages**: two release mechanisms instead of one.
- **`main` in split repositories**, with a generated `branch-alias` and series branches only for maintenance.
  It is fewer mechanisms: no default branch to switch and no tail of a series to push. Rejected for the layout
  of the older repositories: a series is a branch there, and the default branch is one of them.
- **Releasing the head** instead of the commit that added the heading: a change that came later would be
  released under a heading that does not list it.
- **Checking the cascade of series only at the release**: by then the split has pushed the commits
  that need the new series of a dependency into the old series branch, for good.
- **Tags pushed by hand**: the version would be written twice, in the tag and in the changelog, and a tag
  is public before anything checks it. GitHub also starts no workflow for a push of more than three tags.
- **Changesets** (a note per change, collected into a release): a second place for what `[Unreleased]` holds.
- **Releases in the monorepo**: one feed of every package retelling their changelogs, and not the repository
  that Packagist, Dependabot and the users of a package look at.
- **Importing the history of an older repository into the monorepo** to continue its branches: the split drops
  signatures, so the commits of GitHub's web interface get other ids anyway, and the monorepo gets foreign history.
