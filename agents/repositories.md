# Split repositories

Read before touching the split, the repositories of the packages on GitHub or their settings.
The implementation (`bin/monorepo github`, `bin/monorepo split`) is in `tools/monorepo/AGENTS.md`,
the versions are in `versions.md`, the tags and the releases in `releases.md`.

The monorepo `thesis-php/thesis` holds every package in `packages/<name>/`, while composer installs `thesis/<name>`
from a repository of its own. Those repositories are copies of what their directories ship, with the history of it:
a change made there would be lost on the next split or would diverge from the monorepo.

## Rules

- **Split.** CI splits `packages/<name>` of `main` with `bin/monorepo split` (`make split`) and pushes it to `main`
  of `thesis-php/<name>` (`versions.md`), the tags are pushed by the release (`releases.md`). The split keeps
  the history of the directory and is deterministic, so the pushes are never forced. How a split commit is written
  is ours to keep stable: a change to it rewrites every split repository.
- **Only what is shipped.** A split has the entries of the package that `packages/<name>/.split` of the commit
  lists (`manifests.md`): `src/`, `composer.json`, `LICENSE`, `README.md`, `CHANGELOG.md`, `skills/`
  and the top-level entries in `dist` of the manifest (a polyfill, resources that `src/` loads). The tests,
  the manifest and `AGENTS.md` stay in the monorepo, so the repository is exactly what composer installs,
  from the archive and from source alike, and a commit that changes only the rest is not a commit of the split.
  A package without the list is split whole: that is the history before the lists.
- **Push by an app.** A GitHub App of the organization pushes the splits and manages the repositories:
  its id and key are `vars.BOT_CLIENT_ID` and `secrets.BOT_PRIVATE_KEY` of the monorepo.
- **Read-only.** A split repository has pull requests, issues, wiki, projects and discussions disabled.
  Two rulesets, on all branches and on all tags, forbid creation, update, deletion and force pushes to everyone
  but the app, the owners of the organization included. Rulesets of a repository are free for public repositories,
  so no paid plan is needed. A contribution is a pull request to the monorepo only: the homepage of a split
  repository links its directory there, and `make sync` writes a note that says so under the title of `README.md`
  of every package, since GitHub and Packagist show only the README.
- **Settings come from the manifests.** `bin/monorepo github` brings every `thesis-php/<name>` to the state
  derived from `packages/<name>/package.php` and `monorepo.php`: it creates the missing repository, sets
  the description, the topics (`keywords` of the manifest), the homepage (`packages/<name>` in the monorepo),
  the disabled features and the rulesets. Of the monorepo `thesis-php/thesis` it sets only the description
  and the topics, from `description` and `keywords` of `monorepo.php`: the rest of it is set by hand.
  GitHub itself is the state, the command is idempotent and deletes nothing.
  CI runs it before the split, so a new package gets its repository with its first push.
- **Packagist.** `github` also adds the webhook of Packagist to every repository (any webhook to its URL counts:
  the older packages have the one of its GitHub integration) and registers the package once `main` exists,
  by the run after the first split: Packagist reads `composer.json` at once and refuses a repository without it.
  It needs the main API token of a maintainer of `thesis` (`vars.PACKAGIST_USERNAME`, `secrets.PACKAGIST_TOKEN`):
  Packagist creates no package with the safe one. The app needs the permission of repository webhooks.
  The abandoned flag of a reused name is not touched (below).
- **Older repositories of the same name.** `github` refuses a repository that exists without the rulesets
  of the split: the organization holds older packages under the same names, and taking one over is a decision,
  not a side effect of a push. A package named like an older repository fails CI until that repository is renamed,
  deleted or taken over (below). The old name of a renamed repository is free: it only redirects,
  and a reused name loses that redirect (`thesis-php/time` to `thesis-php/time-span`). The abandoned flag
  of a reused Packagist package is removed by hand.
- **Taking over an older repository** is done once, by hand: its own rulesets are deleted, its open issues
  are moved or closed (`github` turns the issues off), branches that are still needed are merged or moved
  to the monorepo, and the rulesets of the split are added. Nothing is force-pushed: the split creates `main`
  where it is missing and fast-forwards it where it pushed already (`amqp`, `exceptionally`).
  The read-only ruleset covers every branch and tag, so the older series end there: they are installable
  as before and get no more commits or tags. The split pushes nothing to a repository without the rulesets
  of the split or to a name that redirects to a renamed one (`Repositories::isSplit()`).

## Decisions

- Rejected: the split as an exact copy of the directory, with `.gitattributes` leaving the rest out of the archive
  only (`tools/monorepo/AGENTS.md`).
- Rejected: `git subtree split`: walks the whole history on every run. Actions that copy files into a new commit:
  no history, no deterministic commits, so every split is a new commit.
- Rejected: splitsh-lite. It links libgit2 through git2go v34, which needs libgit2 1.5 exactly: neither the runners
  of GitHub nor the PHP image have it, so CI built it from source on every push (53 of the 79 seconds of the job).
  `bin/monorepo split` gives the ids splitsh gave for the history so far, so the switch rewrote nothing.
  Also rejected: a fork of splitsh with a prebuilt image (still cgo pinned to libgit2 1.5, plus an image to publish),
  a tool in Go (a toolchain to distribute for a few git commands and a loop), caching the image of splitsh
  (the same dependency on an unmaintained binding).
- Rejected: a workflow that closes pull requests: GitHub can disable them (`has_pull_requests`).
  An archived repository as read-only: the app could not push to it either.
- Rejected: deploy keys or a personal token: a key per repository is a secret per package, a token belongs
  to a person, neither can create repositories for the organization.
- Rejected: Terraform (a state of its own and a second description of the packages), the settings app of Probot
  (a configuration file in each repository and weak support of rulesets).
- Rejected: leaving the older branches out of the read-only ruleset: an exception per package in the manifests
  for series that nobody maintains.
- Rejected: importing the history of an older repository into the monorepo to continue its branches: the split
  drops signatures, so the commits of GitHub's web interface get other ids anyway, and the monorepo gets foreign
  history.
