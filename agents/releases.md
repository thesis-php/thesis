# Releases

Read before touching the release (`bin/monorepo release`, `make release`, the job `release` of `check.yaml`),
the tags of the split repositories or their GitHub Releases. The versions and the changelogs are in `versions.md`,
the code in `tools/monorepo/AGENTS.md`.

## Rules

- **A release is a commit.** It turns `[Unreleased]` of the changelogs into version headings (`versions.md`),
  several packages in one commit. A push of `main` releases the headings it added: a heading right under
  `[Unreleased]` whose version the changelog had not before the push (`github.event.before`). The package
  is released as it is at the head of the push. Nothing else is released on a push, so a release that failed
  is never retried with somebody else's later work: it is repaired by a re-run of its job or by a dispatch.
  A push that starts a series of a package that others require also releases its first version: until then
  no dependent can be released.
- **The headings of a push are released only if, for each of them,**
  - `[Unreleased]` of the package is empty: a listed change is not part of this version,
  - the version belongs to the series of the package and comes right after the last tag of that series
    in the split repository (with or without `v`, composer reads them as one version): the next patch
    or its first pre-release, the next pre-release or the stable version of the last one, and `X.Y.0`
    or its first pre-release for a new series. A typo like `0.1.14` for `0.1.4` is refused instead of published
    for good,
  - every tag of that series or above in the split repository is in the history of the split of `main`,
    and the repository has no branch of the series (`0.1.x`, `0.1`): what the split did not write is an older
    package of the same name, and a version or a `0.1.x-dev` of the series would collide with it,
  - no tag of a higher series is in the history of the split being released, and a new series follows
    the last one in that history (`0.2` or `1.0` after `0.1`): a series typed wrong in the manifest
    (`0.1` for `0.10`, `0.3` for `0.2`) is refused like a wrong version,
  - the repository is a split under its own name (`Repositories::isSplit()`),
  - every dependency of `require` on a monorepo package that the previous stable release of the series has too
    has the same generated range, whatever its lower bound: a forgotten new series of a dependent,
  - every monorepo package in `require` is, in the files it ships (what `.gitattributes` keeps, and the list
    itself), the same as its highest tag in the range, or is released by the same push in the range,
    both stable for a stable release: the tests ran against its head, and a user installs a tag.
    So a release of a package releases the dependencies it has changed since their tags,
    and a fix across packages goes out together,
  - every constraint of `require` on a monorepo package is met by an existing tag or by a heading
    of the same push, stable for a stable release: a stable version that needs a beta cannot be installed
    without `minimum-stability`. `require-dev` is not checked, it is not installed with the package.

  Everything is decided before anything is pushed, and a push is released whole or not at all. A tag
  of the version that exists already at the split being released counts as released: the checks of the tag
  are skipped, since later tags of the package or of its dependencies would refuse it, and the release goes on
  with the steps after it, so a re-run continues a release that failed halfway. A tag at any other commit fails.
  A commit that fixes a refused heading without changing its version releases nothing: dispatch it.
  A changelog that was malformed before the push counts as one without versions.
  A forgotten new series of a dependent fails the release, not the push: `main` of a package may hold commits
  that need a newer series of a dependency than its alias says, until the next release.
- **The steps, per package, dependencies first**: the lightweight tag `vX.Y.Z` at the split of the head,
  pushed to the split repository (the push carries its commits, `main` there is left to the split job),
  then its GitHub Release with `target_commitish` set to that commit (GitHub tags the default branch
  otherwise). A step that exists already counts as made, a tag compared by the commit it points to:
  two runs may release the same headings at once. No package is released after one that failed:
  a dependent would require a version that is not there.
- **Releases on GitHub, in the split repository.** The name is the tag, the body is the section of the changelog,
  `prerelease` is set for `-alphaN`, `-betaN`, `-RCN`, and `make_latest` is always sent (GitHub takes `true`
  otherwise), `true` only for the highest stable version of the package. A user subscribes to the releases
  of the repository of the package (Watch, `releases.atom`), and Dependabot and Renovate take their notes
  from there: it is the source of the package on Packagist.
- **The workflow.** The release is a job of the workflow of the checks and `needs` them. A run of `main`
  is in no group shared with another run (a group per `run_id`): a pending run of a group is replaced
  by a newer one, its release with it. Only a pull request cancels its older runs.
  `workflow_dispatch` on `main` takes a commit reachable from `main` and the packages to release.
  It does not run the checks again: the image and the tools float, so an old commit may fail checks it passed.
  It requires a run of the checks of a push of `main` for that commit whose jobs other than `release` succeeded,
  and releases the headings right under `[Unreleased]` of those packages at that commit with the tooling
  of the head of `main`. The release needs the history of the monorepo (`fetch-depth: 0`).

Open:

- The release assumes the package is registered on Packagist, which `github` does not automate yet.

## Decisions

- Rejected: releasing the top heading of every package on every push: a release that failed would be retried
  at the head of the next push, with its work in the tag, and every push would ask GitHub about every package.
- Rejected: the release after the split job, or pushing `main` of the split repository itself: the split job fails
  when any package fails and a replaced run of it ends as cancelled, and a second writer of `main` races
  with the split. The pushed tag carries the commits.
- Rejected: releasing the headings that pass and stopping only the others: everything is decided before the first
  push anyway, the repair is the same dispatch, and a half-released push is harder to reason about.
- Rejected: running the checks again on a dispatch: the image and the global tools float, so a commit that passed
  could fail for reasons it does not have.
- Rejected: a version higher than the last tag instead of the next one: a typo would publish a version for good.
- Rejected: tags of the monorepo (`<name>/vX.Y.Z`), and splitting tags or branches other than `main`:
  nothing needs them without maintenance branches.
- Rejected: releasing the commit that added the heading (`git blame`): a fix on the same day does not change
  the line, and a later edit of the line moves it away from the tag.
- Rejected: tags pushed by hand: the version would be written twice, in the tag and in the changelog, and a tag
  is public before anything checks it. GitHub also starts no workflow for a push of more than three tags.
- Rejected: releases in the monorepo: one feed of every package retelling their changelogs, and not the repository
  that Packagist, Dependabot and the users of a package look at.
