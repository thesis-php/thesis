# tools/monorepo

The monorepo tooling behind `bin/monorepo` (Symfony Console): `sync`, `github`, `split` and `release`.
Rejected: a scaffolding command, a new package is copied by hand after an existing one.

- `Manifest` and `Config` are the public format of `packages/*/package.php` and `monorepo.php`, the only `@api`.
  Keep them plain readonly DTOs with named arguments: humans and agents write them by hand. The rest is in `Internal\`.
- `packages/<name>/.split` (`Monorepo::SPLIT`) lists the entries of the package that its split repository has
  (`agents/repositories.md`). An allowlist: a new file of the monorepo stays out of the split, and so out
  of every install, without anyone remembering to exclude it. `sync` throws on a `dist` path that does not exist
  or is not a top-level entry: the split keeps whole entries, a path inside one would name nothing.
  Include `src/` in Composer mappings, Testo paths and the split only when the directory exists.
  Declare Composer executables in `Manifest::$bin`. `sync` checks that each path names a file in the package
  and includes its top-level entry in the split.
  Rejected: a list of excluded files (a forgotten one leaks into every install); `.gitattributes`
  with `export-ignore` over an exact copy of the package (only the archive was trimmed: the repository,
  an install from source and `dev-main` of Packagist had the tests and the files of the monorepo,
  and a commit that changed only them was a commit of the split).
- Every composer.json is generated entirely, from typed properties of `Manifest` and `Config`: a key without
  a property does not exist, so nothing is read back from the current file. `type` (default `library`)
  and `extra` are written as given: `extra` is free-form by the design of composer, a config of a plugin
  (phpstan/extension-installer for `packages/phpstan-extension`). Rejected: a raw array of composer.json keys
  merged last, it bypasses the model; a library modelling composer.json (symplify/composer-json-manipulator
  is abandoned), our DTOs are that model.
- The quality tools (PHP CS Fixer, Rector, PHPStan, composer-dependency-analyser, Infection, Tally,
  composer-normalize) are `requireDev` of `monorepo.php`, their versions are in the lock, their configs are
  in the root under the names the tools look for, so `make` passes no `--config`. Rejected: the tools
  of the Docker image: a rebuilt image changed their versions under a commit that changed nothing.
- Register an optional dependency exception only while the root has it as a dev dependency. Once another package
  requires it at runtime, the dependency analyser reports the exception as unused.
- Exceptions of a package to the quality tools are typed fields of `Manifest`, with paths relative to the package, so a
  renamed package or file does not leave a stale entry in a config: `optional` (a dev dependency used in `src/` behind a
  check) for composer-dependency-analyser, `FixerSkip` and `RectorSkip` with a required reason. The configs in the root
  read them with `Tools::of(Monorepo::load(__DIR__))`: the tools are in `vendor/` and load our autoloader. `Tools` holds
  every loop over the packages, so a config has only the settings of its tool and calls (`.php-cs-fixer.php`). `sync`
  calls it too and throws on a skipped path that does not exist. A mutant of Infection is skipped in the code
  (`agents/tests.md`), so `infection.json5` takes nothing from the manifests. Rejected: a generated file of plain arrays
  that the configs `require` (`tools/manifests.php`, while the tools came from the Docker image without our autoloader):
  PHP CS Fixer and Rector had to skip it, or they would fight with `sync` over its imports and their order. `optional`
  covers the whole `src/` of the package, not single files: precise paths are bookkeeping that only shields a forgotten
  check. Skip the ignore when another package makes the dependency a runtime requirement of the root.
  PHPStan is not modelled: `@phpstan-ignore` in the code is precise and reports itself when it is no longer
  needed. A fixer skip excludes the whole file: the fixer cannot turn off one rule for one file.
- `Monorepo::load()` reads the manifests, `Generator` builds every derived file, `sync` writes the changed ones.
- `Series` is the only place that turns `Manifest::$version` into a range (`^0.Y`, `^X.0`) and an alias
  (`X.Y.x-dev`), for `sync` and for the release alike (`agents/versions.md`).
- A Composer project can set `devMain: true` to install every Thesis dependency from `dev-main`.
  The generator follows runtime dependencies from both `require` and `require-dev`. Packages needed only for
  development stay in `require-dev`. Composer
  only accepts stability overrides from the root project. External requirements keep their declared constraints.
- No validator and no `check` command: a second source of truth. An error is whatever keeps `sync` from
  generating the files, thrown as `MonorepoException` (one line per problem, with a path and what to do)
  before anything is written. CI runs `make sync` and fails on any change in git.
- `Requirements` resolves the constraints of external dependencies and php with composer/semver
  and throws with every conflict at once. A platform requirement (`php-64bit`, `ext-*`) is one of them:
  declared in `require` of a manifest, it goes to the root too, one vendor serves the whole monorepo.
  There is no platform of every package in `Config` (`agents/platform.md`).
- The monorepo generator does not check conventions (dependency direction, references between packages, namespaces,
  `AGENTS.md`): a check is added when a real problem shows up, not in advance, and preferably by running
  the final tool (composer, composer-dependency-analyser) rather than by reimplementing it.
  Rejected: layers, no cycles, a validator of code references against `require`.
- `sync` throws on a link of `README.md` of a package out of the package (`../async`, or `/AGENTS.md`,
  which GitHub resolves from the root of the repository): it works only in the monorepo, the split repository
  and Packagist show the package alone. A package links another by `https://github.com/thesis-php/<name>`.
  Links in code (fenced blocks, inline code) are examples and are skipped.
- `sync` generates the head of `README.md` of every package, between `HEAD_START` and `HEAD_END` of `Generator`
  at the top: the title, the badges and a note that the package is a split, since the split repository
  has neither the tests nor pull requests (`agents/repositories.md`). Everything generated is inside the markers,
  everything after them is written by hand. The title is `title` of the manifest or the words of the directory,
  so every package is named alike. A README without the markers gets them at the top, its title is replaced.
  The badges are generated so that every package has the same set: the PHP of Packagist, the release
  of the split, the license, the level read from `phpstan.neon`, the coverage of the component of the package
  in Codecov, which `sync` writes to `.github/codecov.yml`, and the mutation score of the module of the package
  in the Stryker Dashboard (both in `agents/tests.md`). A package without `tests/` has no coverage badge
  and no component: 0 % of code that another tool checks (`phpstan-extension`). A package out of the job `infect`
  (`Package::$isMutationTested`) has no mutation badge: nothing sends its score, the badge would say `unknown`.
  Rejected: the badge of the CI of the monorepo: it is one for all the packages and says nothing of this one.
  The downloads of Packagist: a new package shows a number near zero.
  The table of packages in the root `README.md` has the release badge of each.
- Under the note are links to the forms of a bug and a feature of the monorepo, the changelog
  and `CONTRIBUTING.md`. The forms (`.github/ISSUE_TEMPLATE/bug.yml`, `feature.yml`) are generated: the package
  is a dropdown of the packages, so a report names one exactly. Rejected: an input filled in by the link
  (`?package=thesis/<name>`, GitHub fills in only a text field from the query): it saved a click and let a typo in.
  `labels` of the query needs the right to label, which a reporter does not have.
- `CONTRIBUTING.md` and `SECURITY.md` are generated for every package and shipped in the split, GitHub shows them
  in the split repository: how to report an issue and make a pull request in the monorepo, with the commands
  narrowed to the package, and that a vulnerability goes privately to the monorepo, whose `SECURITY.md`
  is the policy. `composer.json` of a package has `support` (issues, source, security) of the monorepo:
  Packagist would show the issues of the split, which are disabled.
  Rejected: adding the note in the split, which copies the files of a commit as they are. A section "Contributing"
  at the end of the README: it repeated the note, a file of its own has room for the steps.
- `Matrices` writes the matrices of the jobs of `check.yaml` and `infection.yaml` that run per package (`agents/manifests.md`):
  the key under `# <job> (generated by bin/monorepo sync, do not edit)` is replaced, its line and the lines
  indented deeper than the marker, the rest of the workflow is written by hand. An item of a matrix is a line
  of its own: a new package is a line of the diff, not a change in a line of 400 characters. No end marker:
  the indentation ends the key as it does in YAML. `sync` throws on a marker of an unknown job and on a matrix
  without its marker.
  Rejected: generating the whole workflow (its steps and comments in a heredoc of PHP), a job that lists
  the packages at run time (`ls packages/*/tests`: a runner more before the matrix, and it saw only the layout,
  not the manifests). The versions of `test-alone` are the minors from the lower bound of `php` of the manifest
  to the one before the monorepo, within a major only: the last minor of a major is not known from the numbers.
  A matrix that comes out empty fails the workflow: GitHub refuses a job without a vector.
- Generated JSON is compared by meaning, not by text: `composer normalize` owns the formatting of composer.json.
- `github` (`Internal\GitHub\Repositories`) brings `thesis-php/<dir>` to the state of `agents/repositories.md`:
  creates it, sets the settings that differ, creates or replaces the two rulesets, adds the webhook of Packagist
  and registers the package there (`Internal\Packagist`). Of the monorepo itself it syncs only the description
  and the topics. Topics are compared as a set: GitHub keeps its own order. It compares only the keys it sends
  (`Json::canonical`): GitHub returns more and in its own order. CI runs it with the token of the app,
  locally `GITHUB_TOKEN=$(gh auth token) make github ARGS=--dry-run`: a dry run reads Packagist without
  `PACKAGIST_USERNAME` and `PACKAGIST_TOKEN`, only a write needs them. Whether a package is registered
  is `GET packages/<name>.json`: Packagist caches a 200 for hours, but not a 404, so the run after
  the registration finds it.
- A failure of one package does not block the others, the job still fails: `github` collects an error
  per repository and goes on, CI runs `split` after a failed `github`, and `split` pushes in parallel
  and reports every failed push at the end. `split` pushes only to a repository with the rulesets of the split
  under its own name (`Repositories::isSplit()`, so it needs `GITHUB_TOKEN` too): GitHub redirects the old name
  of a renamed repository, the rulesets of the API and `git push` alike. Rejected: relying on the push failing there:
  an older package of the same name lacks the branch (`main`), so the push creates it instead of fast-forwarding.
  That is how the app created `main` in the older `amqp` and `exceptionally`.
- `github` refuses an existing repository without our rulesets (`agents/repositories.md`). A name that GitHub
  redirects to a renamed repository (`full_name` differs) is free: the repository is created, which ends the redirect.
- The organization, the default branch and the id of the app are constants of `Repositories`, not of `Config`:
  they describe the infrastructure, not the packages, and nobody writes them by hand.
- Files and HTTP go through `exceptionally()` (`agents/errors.md`), the error goes into
  the `MonorepoException` or the output of the command: `sync` used to succeed without writing a file.
  `@` stays in `Monorepo::read()` of a missing file, the check of a loose object of an earlier run
  and the cleanup of a temporary file.
- HTTP goes through `file_get_contents()` with a stream context behind the `Api` interface: a few JSON requests
  do not need a client library. Tests use `FakeApi`.

## split

`make split` (`Internal\Split`) splits every `packages/<name>` of `refs/heads/main` and pushes each split to `main`
of `thesis-php/<name>`, all pushes in parallel. Without `SPLIT_REMOTE`
(`https://x-access-token:<token>@github.com/thesis-php`) it only prints the commits: that is how to try it locally.
CI (`.github/workflows/split.yaml`) runs it after `github` on every push of `main`. No other ref is split
(`agents/versions.md`): a version is a tag that the release pushes.

Invariants:

- A split is deterministic: the same commit of the monorepo gives the same commit of a package on any machine,
  and the pushes are never forced. How a commit is written (the headers copied, the parents kept, their order,
  the entries of its tree) is part of the ids of every published history: a change to it rewrites all
  split repositories.
  `givesTheSameIdsForEveryKindOfCommit` pins the ids, checked once against a model built with `git commit-tree`,
  and `givesTheSameIdsForLinearHistory` a history that splitsh-lite gave the same ids for: the switch from it
  needed no force push.
- The tree of a split has the entries of `packages/<name>` that `.split` of the same commit names: one name
  per line, and a line that names no entry (the header of the file) keeps nothing, so the format has no syntax
  to change. A package without the file is split whole, as every commit before the lists was: their ids are
  published. The list is read from the commit it filters and from nowhere else: the same list for the whole
  history (the manifest of the head) would change the trees of the older commits, and with them every id,
  each time it changes. A changed list is an ordinary commit of the split that adds or removes an entry.
- Only the commit `main` points to is split, resolved once (`show-ref --verify`, then `rev-parse`), never
  the working tree or the checked-out branch.
- Nothing outside the objects decides a split: git runs without the caller's `GIT_*` variables, without replace
  refs and grafts (`Git::ENV`).
- A failure is an exception before any push, never an `assert`: with assertions off an empty commit id would make
  the refspec `:<ref>`, which deletes the branch of the split repository, and the app bypasses the rulesets.

Decisions:

- Our own split instead of splitsh-lite and the other tools rejected in `agents/repositories.md`.
  Ours is one `git rev-list --topo-order` and one
  `git cat-file --batch` for all packages at once, ids are `sha1()` in PHP and the commits are written
  as loose objects directly. No cache: the cache of splitsh was keyed by the name of the ref, so a ref moved back
  got its old split.
- Only the frontier of the walk is in memory: the heads of a commit are dropped after its last child,
  and `SplitCommit` names its parents by id. Heads per package and commit exhausted the memory of a long history,
  and objects holding their parents crashed PHP when it freed a chain of about 60 000 of them.
  `keepsMemoryOfHeadsFlatAsHistoryGrows` guards the first, the id-typed parents the second: a test of 60 000
  commits would take half a minute of `fast-import`.
- A commit without the package passes the split heads of its parents to its children: a package removed and added
  again continues its history (splitsh started a new root, which could not be pushed without a force).
  A commit where `packages` is a file or a link has no packages, it does not break the splits after it.
- A package is split by the name of its directory, renames are not followed: a renamed `packages/<name>` starts
  a new root, which cannot fast-forward the history of the old name. So a package is renamed together with
  a new repository: delete the old repository (`github` creates it again), do not rename it on GitHub
  (`testo` → `testo-plugin` was rejected by the next split).
- A parent that is an ancestor of another parent is dropped and a repeated one is kept once, so a merge
  is kept only where both sides changed the package. A commit is reused when its only parent has the same tree.
- Author, committer, encoding and message are copied byte for byte, every other header is dropped: a signature
  (`gpgsig`, `mergetag`) signs the commit of the monorepo, not the split.
- The tree of a split is written like its commits, as a loose object made of the entries of the package
  byte for byte (a mode is `40000` or `040000` as it was). `keepsOnlyEntriesThatListOfPackageNames` checks
  that it is the object git writes for the same files. The tree is made once per change of the package,
  and it remembers only the latest entry for each package. Memory use stays bounded as history grows.
- Rejected for the list: `package.php` of the commit (code, not data: it cannot be run with the classes
  of the head, the same as for the release below, and the list is also the rules of `Generator` of that commit);
  an incremental split that adds a commit on top of the split repository (a state to fetch, and the release
  could not compute the commit of a tag from the monorepo alone); moving what is shipped into a subdirectory
  (a level more in the path to every class) or making `src/` the root of the split (`composer.json`,
  `README.md` and the polyfill of `time` inside the psr-4 root); josh or git-filter-repo (a tool to install
  for what is a filter of one tree).
- A loose object left by an earlier run is kept only if it inflates to this very object: a run killed while
  writing leaves a broken one.
- The credentials of `SPLIT_REMOTE` go to the environment of `git push` as `url.<with>.insteadOf <without>`
  (`Remote`): the arguments of a process are visible to everyone in `ps`.
- The check that every parent is split before its children is unreachable while `--topo-order` holds,
  no test can trigger it: it turns a broken order into an error instead of a split with a new root.

## release

`make release` (`Internal\Release`, `ReleaseCommand`) releases the changelog headings at `COMMIT` (default `HEAD`)
that have no tag in the split repositories: the rules are in `agents/releases.md`. Without `SPLIT_REMOTE` it reads
the public repositories and only prints what it would release, it needs `GITHUB_TOKEN` either way
(`Repositories::isSplit()`, the GitHub Releases): `GITHUB_TOKEN=$(gh auth token) COMMIT=origin/main make release`.
CI runs it as the job `release` of `.github/workflows/check.yaml`.

Invariants:

- The release knows three things: the tree of the commit (the changelogs and the generated `composer.json`
  of the packages, read from the objects, never from the working tree or by running PHP of it), the tags
  of the split repositories (`git ls-remote` of the packages with a heading, nothing is fetched) and two answers
  of GitHub (`Repositories::isSplit()`, `GET releases/tags/<tag>` of the split repository and of the monorepo:
  `Publisher::hasGitHubRelease()`). Nothing else decides: no other commit of the monorepo, no object
  of a split repository, no branch of it, no tag of the monorepo.
  A new check must fit into that, or it is not added (`agents/releases.md`, "What the release does not check").
- A test that the commit of a tag is not read needs a tag at a split the clone cannot compute: the clone is made
  first, the tag is of a later commit (`continuesReleaseAtCommitOfItsTagThatIsNotLocal`). A tag at a split
  of an ancestor is written into the clone by the split of the release itself, and such a test passes
  on code that reads it.
- `Plan::of()` decides everything before `Publisher` pushes anything, and returns either the releases,
  dependencies first, or the errors: with one error nothing is released. A `MonorepoException` while deciding
  about one package (GitHub, its `composer.json`) is an error of that package, so the others still report theirs.
  The other reasons of that package are lost with it. Rejected: collecting them around every call that throws:
  GitHub failing is transient, nothing is released, and the next run prints the rest.
- Whether a heading is released is decided by the tag `vX.Y.Z` alone: without a tag of the version it is checked
  and released, with the tag only the GitHub Releases that are missing are made (its own and the one
  of the monorepo), and a tag of the version without `v` is of an older package of the same name.
- The monorepo repeats a release by one request (`Publisher::mirror()`), in a second pass of `ReleaseCommand`
  over the releases. The commit of its tag `<package>/vX.Y.Z` (`Release::$mirrorTag`) is `Release::$origin`:
  `Splitter` remembers the commit of the monorepo that every split commit was made from (`Splits::$origins`),
  so it is known for the split of the head and for a tag of any ancestor. It is `null` for a tag of a later
  commit, and then `Plan` does not ask GitHub about the monorepo and `mirror()` only says so.
- The series is the `branch-alias` of the generated `composer.json` (`Series::ofComposer()`), which CI keeps
  in sync with the manifest.
- Versions in the split repositories are read by composer/semver (`Refs`), so `v0.1.4` and `0.1.4` are one
  version and an older tag in a format of its own still counts as a version. `Version` is only what the release
  writes: `X.Y.Z` and `-alphaN`, `-betaN`, `-RCN`.

Decisions:

- Rejected: reading the version from `package.php` of the commit: a manifest of another commit cannot be run
  with the classes of the head, and parsing PHP by hand breaks on the first refactoring.
- Rejected: fetching the tags of every split repository into the monorepo: `ls-remote` answers the same questions
  without writing refs that a later run would have to prune.
- Rejected: telling our tags from an older package's tags by the history (`merge-base --is-ancestor`
  with the split of every head of `main`, fetched first): a clone has only the splits it computes, and each
  shape of a checkout in CI was a bug of its own. The `v` tells them apart.
