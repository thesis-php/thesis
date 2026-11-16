# tools/monorepo

The monorepo tooling behind `bin/monorepo` (Symfony Console): `sync`, `github` and `split`.
Rejected: a scaffolding command, a new package is copied by hand after an existing one.

- `Manifest` and `Config` are the public format of `packages/*/package.php` and `monorepo.php`, the only `@api`.
  Keep them plain readonly DTOs with named arguments: humans and agents write them by hand. The rest is in `Internal\`.
- `.gitattributes` of a package is an allowlist (`/* export-ignore`, then `-export-ignore` for what is kept):
  the split repository stays an exact copy of the package (ADR 0002), only the archive is trimmed, and a new file
  of the monorepo stays out of it without anyone remembering to exclude it. `sync` throws on a `dist` path that
  does not exist. Rejected: excluding files from the split itself (every split commit would differ from its directory
  and nobody gains from it: users install the archive); a list of excluded files
  (a forgotten one leaks into every install).
- Every composer.json is generated entirely, from typed properties of `Manifest` and `Config`: a key without
  a property does not exist, so nothing is read back from the current file. `type` (default `library`)
  and `extra` are written as given: `extra` is free-form by the design of composer, a config of a plugin
  (phpstan/extension-installer for `packages/phpstan-extension`). Rejected: a raw array of composer.json keys
  merged last, it bypasses the model; a library modelling composer.json (symplify/composer-json-manipulator
  is abandoned), our DTOs are that model.
- Exceptions of a package to the quality tools are typed fields of `Manifest`, with paths relative to the package,
  so a renamed package or file does not leave a stale entry in `tools/*.php`: `optional` (a dev dependency used in
  `src/` behind a check) for composer-dependency-analyser, `FixerSkip` and `RectorSkip` with a required reason.
  `sync` throws on a skipped path that does not exist. The tools are installed globally and do not load our
  autoloader, so `sync` writes plain arrays to `tools/manifests.php` and their configs `require` it.
  PHP CS Fixer and Rector skip it: to write what they want, `sync` would have to repeat their rules (imports,
  their order), and otherwise they would fight with `sync` over the file. `optional` covers the whole `src/`
  of the package, not single files: precise paths are bookkeeping that only shields a forgotten check.
  PHPStan is not modelled: `@phpstan-ignore` in the code is precise and reports itself when it is no longer needed.
  A fixer skip excludes the whole file: the fixer cannot turn off one rule for one file.
- `Monorepo::load()` reads the manifests, `Generator` builds every derived file, `sync` writes the changed ones.
- No validator and no `check` command: a second source of truth. An error is whatever keeps `sync` from
  generating the files, thrown as `MonorepoException` (one line per problem, with a path and what to do)
  before anything is written. CI runs `make sync` and fails on any change in git.
- `Requirements` resolves the constraints of external dependencies, php and `Config::$platform` with composer/semver
  and throws with every conflict at once.
- Conventions (dependency direction, references between packages, `@api`/`@internal`, namespaces, `AGENTS.md`)
  are not checked: a check is added when a real problem shows up, not in advance, and preferably by running
  the final tool (composer, composer-dependency-analyser) rather than by reimplementing it.
  Rejected: layers, no cycles, a validator of code references against `require`.
- Generated JSON is compared by meaning, not by text: `composer normalize` owns the formatting of composer.json.
- `github` (`Internal\GitHub\Repositories`) brings `thesis-php/<dir>` to the state of ADR 0002: creates it, sets
  the settings that differ, creates or replaces the two rulesets. It compares only the keys it sends
  (`Json::canonical`): GitHub returns more and in its own order. CI runs it with the token of the app,
  locally `GITHUB_TOKEN=$(gh auth token) make github ARGS=--dry-run`.
- A failure of one package does not block the others, the job still fails: `github` collects an error
  per repository and goes on, CI runs `split` after a failed `github`, and `split` pushes in parallel
  and reports every failed push at the end. `split` pushes only to a repository with the rulesets of the split
  under its own name (`Repositories::isSplit()`, so it needs `GITHUB_TOKEN` too): GitHub redirects the old name
  of a renamed repository, the rulesets of the API and `git push` alike. Rejected: relying on the push failing there:
  an older package of the same name lacks the branch (`main`), so the push creates it instead of fast-forwarding.
  That is how the app created `main` in the older `amqp` and `exceptionally`.
- `github` refuses an existing repository without our rulesets: older packages of the organization share names
  with new ones. Taking one over is done by hand, by adding the rulesets. A name that GitHub redirects to a renamed
  repository (`full_name` differs) is free: the repository is created, which ends the redirect.
- The organization, the default branch and the id of the app are constants of `Repositories`, not of `Config`:
  they describe the infrastructure, not the packages, and nobody writes them by hand.
- Files and HTTP are written and read inside `exceptionally()` of thesis/exceptionally, and the error goes into
  the `MonorepoException` or the output of the command: a bare call only warns and goes on (`sync` used to
  succeed without writing a file), `@` hides the reason. `@` stays where a failure is an answer, not an error:
  `Monorepo::read()` of a missing file, the check of a loose object of an earlier run, the cleanup of a temporary file.
- HTTP goes through `file_get_contents()` with a stream context behind the `Api` interface: a few JSON requests
  do not need a client library. Tests use `FakeApi`.

## split

`make split [REF=refs/heads/main]` (`Internal\Split`) splits every `packages/<name>` of a branch or a `v*` tag
and pushes each split to the same ref of `thesis-php/<name>`, all pushes in parallel. Without `SPLIT_REMOTE`
(`https://x-access-token:<token>@github.com/thesis-php`) it only prints the commits: that is how to try it locally.
CI (`.github/workflows/split.yaml`) runs it after `github` on every push of a branch or a tag.

Invariants:

- A split is deterministic: the same commit of the monorepo gives the same commit of a package on any machine,
  and the pushes are never forced. How a commit is written (the headers copied, the parents kept, their order)
  is part of the ids of every published history: a change to it rewrites all split repositories, and since
  every `*.*.x` branch and tag runs the splitter of its own checkout, it has to reach the maintained branches too.
  `givesTheSameIdsForEveryKindOfCommit` pins the ids, checked once against a model built with `git commit-tree`,
  and `givesTheSameIdsForLinearHistory` a history that splitsh-lite gave the same ids for: the switch from it
  needed no force push.
- Only the commit the ref points to is split, resolved once (`show-ref --verify`, then `rev-parse`), never
  the working tree and never a revision like `main~1`, which would push another commit under the name of the ref.
- Nothing outside the objects decides a split: git runs without the caller's `GIT_*` variables, without replace
  refs and grafts (`Git::ENV`), and versions are compared in PHP (`Version`), not by `git tag --sort`,
  whose order depends on the user's config.
- A failure is an exception before any push, never an `assert`: with assertions off an empty commit id would make
  the refspec `:<ref>`, which deletes the branch of the split repository, and the app bypasses the rulesets.
- A tag is `vX.Y.Z` or `vX.Y.Z-alphaN`, `-betaN`, `-RCN`, anything else under `v*` fails. A stable tag goes to
  a package only if `packages/<name>` changed (the tree ids differ, a package added counts) since the highest lower
  version among the tags merged into it, and only if that one is stable and of the same minor series.
  The first tag of a series, a pre-release and the first stable tag after a pre-release go to every package:
  otherwise a package would keep only an alpha while the others are stable.

Decisions:

- Our own split instead of splitsh-lite (ADR 0003): splitsh needs libgit2 1.5 exactly, so CI built it in a Docker
  image on every run. Ours is one `git rev-list --topo-order` and one `git cat-file --batch` for all packages
  at once, ids are `sha1()` in PHP and the commits are written as loose objects directly. No cache: the cache
  of splitsh was keyed by the name of the ref, so a ref moved back got its old split.
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
  a new repository: delete the old one (`github` creates it again), do not rename it on GitHub
  (`testo` → `testo-plugin` was rejected by the next split).
- A parent that is an ancestor of another parent is dropped and a repeated one is kept once, so a merge
  is kept only where both sides changed the package. A commit is reused when its only parent has the same tree.
- Author, committer, encoding and message are copied byte for byte, every other header is dropped: a signature
  (`gpgsig`, `mergetag`) signs the commit of the monorepo, not the split.
- A loose object left by an earlier run is kept only if it inflates to this very object: a run killed while
  writing leaves a broken one.
- The credentials of `SPLIT_REMOTE` go to the environment of `git push` as `url.<with>.insteadOf <without>`
  (`Remote`): the arguments of a process are visible to everyone in `ps`. The ref reaches `make split`
  through the environment, quoted: a tag name may hold `;`, `$`, backquotes and quotes.
- The check that every parent is split before its children is unreachable while `--topo-order` holds,
  no test can trigger it: it turns a broken order into an error instead of a split with a new root.
- Rejected: `git subtree split` (a process per commit, slow as the history grows); a fork of splitsh with
  a prebuilt image (still cgo pinned to libgit2 1.5, plus an image and a registry to maintain); a tool in Go
  (a toolchain to distribute for what is git plumbing); an action that copies files into a new commit
  (no history, no deterministic commits); filtering files out of the split (see `.gitattributes` above).
