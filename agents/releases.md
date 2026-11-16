# Releases

Read before touching the release (`bin/monorepo release`, `make release`, the job `release` of `check.yaml`),
the tags of the split repositories or their GitHub Releases. The versions are in `versions.md`,
the changelogs in `changelogs.md`, the code in `tools/monorepo/AGENTS.md`.

## Rules

- **A release is a commit.** It turns `[Unreleased]` of the changelogs into version headings (`changelogs.md`),
  several packages in one commit. Every push of `main` whose checks passed releases every heading right under
  `[Unreleased]` that has no tag in the split repository of its package, as the package is at the head of the push.
  So a release that failed (a flaky test, GitHub down) is released by the next push that passes, or by a re-run
  of the failed jobs, with nothing to do by hand. A heading whose version is tagged by an older package
  of the same name (`exceptionally` 0.3.0) is that package's: it is skipped.
- **A heading without a tag is released only if**
  - `[Unreleased]` of the package is empty: a listed change is not part of this version. A change listed
    after the heading and before its release is moved under the heading,
  - the heading under it has a tag: only the top heading is released, so one under it without a tag
    (its push failed, the next one added a new series on top) would never be. It is released by a re-run
    of the job of its push, or merged into the top one,
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
    itself), the same as its highest tag in the range, or is released with it in the range,
    both stable for a stable release: the tests ran against its head, and a user installs a tag.
    So a release of a package releases the dependencies it has changed since their tags,
    and a fix across packages goes out together,
  - every constraint of `require` on a monorepo package is met by an existing tag or by a heading
    released with it, stable for a stable release: a stable version that needs a beta cannot be installed
    without `minimum-stability`. `require-dev` is not checked, it is not installed with the package,
  - no monorepo package in `require` has a heading without a tag that is refused.

  Everything is decided before anything is pushed. A refused package keeps back itself and the packages
  that require it, the others are released and the job fails with every reason: a broken changelog or a typo
  in one package does not stop the release of the rest, and the failed job shows it on every push until fixed.
  A tag of the version that the split wrote (in the history of a split of `main`) counts as released:
  its GitHub Release is made if it is missing, at the commit of the tag and with the notes of the changelog
  of the tag, so a release that failed halfway is continued by the next run. A section of a tagged version
  is not edited: notes that differ from those of a tag at or before the split refuse the package, since
  a change listed there after the tag (the author could not know that a run had tagged it) is not in the tag.
  It goes to `[Unreleased]`. A tag of a later split is not compared: a later push amended the notes before
  it released them, and the run of an older push only finds the version released.
  An error of GitHub or a broken package (no `composer.json`) refuses that package, not the others.
- **The steps, per package, dependencies first**: the lightweight tag `vX.Y.Z` at the split of the head,
  pushed to the split repository (the push carries its commits, `main` there is left to the split job),
  then its GitHub Release with `target_commitish` set to that commit (GitHub tags the default branch
  otherwise). A step that exists already counts as made, a tag compared by the commit it points to.
  Two runs of `main` may release the same heading at once: the tag is at the split of whichever pushed first,
  the other run fails on it and the next one finds it released. A package that failed keeps back the packages
  that require it: they would require a version that is not there. The others are released.
- **Releases on GitHub, in the split repository.** The name is the tag, the body is the section of the changelog,
  `prerelease` is set for `-alphaN`, `-betaN`, `-RCN`, and `make_latest` is always sent (GitHub takes `true`
  otherwise), `true` only for the highest stable version of the package. A user subscribes to the releases
  of the repository of the package (Watch, `releases.atom`), and Dependabot and Renovate take their notes
  from there: it is the source of the package on Packagist.
- **The workflow.** The release is a job of the workflow of the checks and `needs` them: it runs only on a push
  of `main` whose checks passed. A run of `main` is in no group shared with another run (a group per `run_id`):
  a pending run of a group is replaced by a newer one, its release with it. Only a pull request cancels
  its older runs. The release needs the history of the monorepo (`fetch-depth: 0`).

Open:

- The release assumes the package is registered on Packagist, which `github` does not automate yet.

## Decisions

- Rejected: releasing only the headings that a push added (the first design). A push whose checks failed
  lost its release: a re-run ran the old code of the flaky test again, and a dispatch (`workflow_dispatch`
  with a commit and the packages) needed a run of the checks that passed for that very commit, so a fix
  after it could not release it. Its reasons do not hold: the work of a later push is in the tag only
  if it is not listed in `[Unreleased]` (a listed change refuses the release), and a push lists the tags
  of the packages with a heading, a `git ls-remote` each.
- Rejected: the release after the split job, or pushing `main` of the split repository itself: the split job fails
  when any package fails and a replaced run of it ends as cancelled, and a second writer of `main` races
  with the split. The pushed tag carries the commits.
- Rejected: reading a top heading that is not a version the release writes (`0.5.0-rc.1`) as a tag
  of an older package: the headings of the older packages are a fixed set (`dispatcher` 0.2.0,
  `exceptionally` 0.3.0, `formatter` 0.1.0), all parse, and a new heading is written in our format.
- Rejected: a push released whole or not at all: one broken changelog or typo held back every package,
  and a refusal is only seen again when somebody looks. A refusal keeps back the package and its dependents.
- Rejected: a version higher than the last tag instead of the next one: a typo would publish a version for good.
- Rejected: tags of the monorepo (`<name>/vX.Y.Z`), and splitting tags or branches other than `main`:
  nothing needs them without maintenance branches.
- Rejected: releasing the commit that added the heading (`git blame`): a fix on the same day does not change
  the line, and a later edit of the line moves it away from the tag.
- Rejected: tags pushed by hand: the version would be written twice, in the tag and in the changelog, and a tag
  is public before anything checks it. GitHub also starts no workflow for a push of more than three tags.
- Rejected: releases in the monorepo: one feed of every package retelling their changelogs, and not the repository
  that Packagist, Dependabot and the users of a package look at.
