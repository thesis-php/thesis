# Releases

Read before touching the release (`bin/monorepo release`, `make release`, the job `release` of `check.yaml`),
the tags of the split repositories or their GitHub Releases. The versions are in `versions.md`,
the changelogs in `changelogs.md`, the code in `tools/monorepo/AGENTS.md`.

## Rules

- **A release is a commit** that turns `[Unreleased]` into version headings (`changelogs.md`), of several packages
  at once. Every push of `main` whose checks passed releases, as the package is at the head, the heading right under
  `[Unreleased]` of every package whose version has no tag in the split repository. A failed release (a flaky test,
  GitHub down) is released by the next push that passes or by a re-run of the failed jobs.
- **The tag decides.** A heading whose tag `vX.Y.Z` exists is released: only its GitHub Release is made if missing,
  at the commit of the tag, with the notes of the changelog as it is now. A tag of the version without `v` is
  of an older package of the same name (`exceptionally` `0.3.0`), whose changelog ends with it: the heading is skipped.
- **A heading without a tag is released only if**
  - it is `## [X.Y.Z] - YYYY-MM-DD` (or `-alphaN`, `-betaN`, `-RCN`) with notes under it,
  - `[Unreleased]` of the package is empty: a change listed after the heading and before its release
    is moved under the heading,
  - the version is in the series of the package and comes right after its highest tag in it (with or without `v`,
    composer reads them as one): the next patch or its first pre-release, the next pre-release or the stable
    version of the last one, `X.Y.0` or its first pre-release for a new series. So a typo (`0.1.14` for `0.1.4`)
    is refused instead of published for good,
  - `[Unreleased]` of every monorepo package in its `require` is empty: the tests ran against the head
    of a dependency, a user installs its tag. So a fix across packages goes out together,
  - the repository is a split under its own name (`Repositories::isSplit()`): a tag pushed to an older package
    or to a renamed repository cannot be taken back.
- **All or nothing.** Everything is decided before anything is pushed. One reason not to release a package
  releases nothing: the job fails with the reasons of every package, and the push that fixes them releases all.
- **The steps, per package, dependencies first**: the lightweight tag `vX.Y.Z` at the split of the head, pushed
  to the split repository (it carries the commits, `main` there is left to the split job), then the GitHub Release
  with `target_commitish` set to that commit (GitHub tags the default branch otherwise). A step that exists counts
  as made, a tag compared by its commit. Of two runs releasing one heading at once, the second fails on the tag
  and the next one finds it released. The run stops at the first failed step, since a dependent would require
  a version that is not there, and a re-run or the next push continues it.
- **GitHub Releases are in the split repository**: the source of the package on Packagist, which users watch
  (`releases.atom`) and Dependabot and Renovate take notes from. The name is the tag, the body is the section
  of the changelog, `prerelease` is set for `-alphaN`, `-betaN`, `-RCN`. `make_latest` is always sent (GitHub takes
  `true` otherwise), `true` only for the highest stable version of the package.
- **The workflow.** The release is a job of the workflow of the checks and `needs` them. A run of `main` has
  a concurrency group of its own (per `run_id`): in a shared group a newer run would replace a pending one,
  its release with it. Only a pull request cancels its older runs. The release needs `fetch-depth: 0`:
  it splits the commit.
- **What the release does not check** is left to the author and the review of the release commit. A check
  is added when the mistake has happened, not in advance: each one multiplies the states of the others.
  - A new series above the tags and branches of an older package of the name, following our last series,
    and a new series of a dependent with a new series of its dependency (`versions.md`).
  - A dependency changed since its tag in what its changelog does not list, a stable version that requires
    a dependency with only pre-releases, a lower bound that no tag meets.
  - A heading under the top one that was never tagged (its push failed, the next one put a new series on top):
    merge its notes into the top one.
  - The notes of a GitHub Release against the tag: a missing one is made with the notes of the commit of the run,
    whatever the tag has, and one that exists is never updated. Both are edited by hand on GitHub.
  - That the package is on Packagist: `github` registers it after its first split (`repositories.md`),
    and Packagist reads the tags made before.

## Decisions

- Rejected: a release that proves every release right before making it (the second design): our tags told
  from older ones by the history of the split, notes compared with the changelog of the tag, shipped files
  with `.gitattributes`, ranges with the previous release, a refusal that keeps back only the package
  and its dependents. Ten reasonable conditions multiplied into states that every review found a new hole in,
  before a single release was made, and reimplemented what composer resolves.
- Rejected: refusing only the broken package and its dependents: a state per package (refused, kept back,
  released) for one maintainer who fixes a changelog in minutes.
- Rejected: releasing only the headings that a push added (the first design): a push whose checks failed lost
  its release, a re-run ran the old flaky test again, and a dispatch needed passed checks for that very commit.
- Rejected: the release after the split job, or pushing `main` of the split repository: the split job fails when
  any package fails, a replaced run of it ends as cancelled, and a second writer of `main` races with the split.
- Rejected: reading a top heading that is not a version the release writes (`0.5.0-rc.1`) as an older package's:
  their headings are a fixed set (`exceptionally` 0.3.0, `formatter` 0.1.0) and all parse.
- Rejected: any version higher than the last tag instead of the next one: a typo would be published for good.
- Rejected: tags of the monorepo (`<name>/vX.Y.Z`), and splitting tags or branches other than `main`:
  nothing needs them without maintenance branches.
- Rejected: releasing the commit that added the heading (`git blame`): a fix on the same day does not change
  the line, and a later edit of the line moves it away from the tag.
- Rejected: tags pushed by hand: the version is written twice, the tag is public before anything checks it,
  and GitHub starts no workflow for a push of more than three tags.
- Rejected: releases in the monorepo: one feed retelling every changelog, not the repository that Packagist,
  Dependabot and the users of a package look at.
