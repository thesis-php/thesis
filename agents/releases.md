# Releases

Read before touching the release (`bin/monorepo release`, `make release`, the job `release` of `check.yaml`),
the tags of the split repositories or their GitHub Releases. The versions are in `versions.md`,
the changelogs in `changelogs.md`, the code in `tools/monorepo/AGENTS.md`.

## Rules

- **A release is a commit.** It turns `[Unreleased]` of the changelogs into version headings (`changelogs.md`),
  several packages in one commit. Every push of `main` whose checks passed releases the heading right under
  `[Unreleased]` of every package whose version has no tag in the split repository, as the package is at the head
  of the push. So a release that failed (a flaky test, GitHub down) is released by the next push that passes,
  or by a re-run of the failed jobs, with nothing to do by hand.
- **The tag decides.** The release writes the tag `vX.Y.Z`. A heading whose version has it is released:
  only its GitHub Release is made if it is missing, at the commit of the tag and with the notes of the changelog
  as it is now. A version tagged without `v` is of an older package of the same name (`exceptionally` `0.3.0`),
  whose changelog ends with it: the heading is skipped.
- **A heading without a tag is released only if**
  - it is `## [X.Y.Z] - YYYY-MM-DD` (or `-alphaN`, `-betaN`, `-RCN`) with notes under it,
  - `[Unreleased]` of the package is empty: a listed change is not part of this version. A change listed
    after the heading and before its release is moved under the heading,
  - the version belongs to the series of the package and comes right after the highest tag of that series
    (with or without `v`, composer reads them as one version): the next patch or its first pre-release,
    the next pre-release or the stable version of the last one, and `X.Y.0` or its first pre-release
    for a new series. A typo like `0.1.14` for `0.1.4` is refused instead of published for good,
  - `[Unreleased]` of every monorepo package in its `require` is empty: the tests ran against the head
    of a dependency, and a user installs its tag. So a package is released together with the dependencies
    that have listed changes, and a fix across packages goes out together,
  - the repository is a split under its own name (`Repositories::isSplit()`): a tag pushed to an older package
    or to a renamed repository cannot be taken back.
- **All or nothing.** Everything is decided before anything is pushed, and one reason not to release a package
  releases nothing: the job fails with the reasons of every package, and the push that fixes them releases all.
- **The steps, per package, dependencies first**: the lightweight tag `vX.Y.Z` at the split of the head,
  pushed to the split repository (the push carries its commits, `main` there is left to the split job),
  then its GitHub Release with `target_commitish` set to that commit (GitHub tags the default branch
  otherwise). A step that exists already counts as made, a tag compared by the commit it points to.
  Two runs of `main` may release the same heading at once: the tag is at the split of whichever pushed first,
  the other run fails on it and the next one finds it released. The run stops at the first step that fails:
  a dependent would require a version that is not there. A re-run or the next push continues it.
- **Releases on GitHub, in the split repository.** The name is the tag, the body is the section of the changelog,
  `prerelease` is set for `-alphaN`, `-betaN`, `-RCN`, and `make_latest` is always sent (GitHub takes `true`
  otherwise), `true` only for the highest stable version of the package. A user subscribes to the releases
  of the repository of the package (Watch, `releases.atom`), and Dependabot and Renovate take their notes
  from there: it is the source of the package on Packagist.
- **The workflow.** The release is a job of the workflow of the checks and `needs` them: it runs only on a push
  of `main` whose checks passed. A run of `main` is in no group shared with another run (a group per `run_id`):
  a pending run of a group is replaced by a newer one, its release with it. Only a pull request cancels
  its older runs. The release needs the history of the monorepo (`fetch-depth: 0`): it splits the commit.
- **What the release does not check** is left to the author and the review of the release commit. A check
  is added when the mistake has happened, not in advance: each one multiplies the states of the others.
  - A new series is above the tags and the branches of an older package of the name and follows the last
    series of ours (`versions.md`). The series of the older names are chosen already.
  - A dependent starts a new series with a new series of its dependency (`versions.md`).
  - A dependency changed since its tag in what its changelog does not list, a stable version requires
    a dependency that has only pre-releases, a lower bound that no tag meets.
  - A heading under the top one that was never tagged (its push failed, the next one put a new series on top):
    merge its notes into the top one.
  - The notes of a GitHub Release against the tag. A GitHub Release that is missing is made with the notes
    of the commit of the run, whatever the tag has: edited after the tag, or older than it when the job
    of an older push is re-run after a later push tagged the version. One that exists is never updated.
    Both are edited by hand on GitHub.
  - That the package is on Packagist: `github` registers it by the push after its first split (`repositories.md`),
    and Packagist reads the tags made before.

## Decisions

- Rejected: the release that proved every release right before making it (the second design): tags of ours
  told from those of an older package by the history of the split, with every head of `main` split for it;
  the notes compared with the changelog of the tag; the files a dependency ships compared with its tag
  by `.gitattributes`; the ranges compared with the previous release; a series checked against the last one;
  a refused package keeping back only itself and its dependents. Ten conditions, each of them reasonable,
  multiplied into states that every review found a new hole in (stable or pre-release, our tag or an older one,
  an earlier split or a later one, a tag without a release, a partial refusal), before a single release was made.
  It also reimplemented what composer resolves. What is left is checked from the commit and a list of tags.
- Rejected: refusing only the broken package and its dependents, the others released: a state per package
  (refused, kept back, released) for one maintainer who fixes a changelog in minutes. The failed job shows
  the reasons on every push until fixed.
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
  of an older package: the headings of the older packages are a fixed set (`exceptionally` 0.3.0,
  `formatter` 0.1.0, `changelogs.md`), all parse, and a new heading is written in our format.
- Rejected: a version higher than the last tag instead of the next one: a typo would publish a version for good.
- Rejected: tags of the monorepo (`<name>/vX.Y.Z`), and splitting tags or branches other than `main`:
  nothing needs them without maintenance branches.
- Rejected: releasing the commit that added the heading (`git blame`): a fix on the same day does not change
  the line, and a later edit of the line moves it away from the tag.
- Rejected: tags pushed by hand: the version would be written twice, in the tag and in the changelog, and a tag
  is public before anything checks it. GitHub also starts no workflow for a push of more than three tags.
- Rejected: releases in the monorepo: one feed of every package retelling their changelogs, and not the repository
  that Packagist, Dependabot and the users of a package look at.
