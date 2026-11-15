# tools/split

`make split [REF=refs/heads/main]` splits every `packages/<name>` of a branch or a `v*` tag with splitsh-lite
and pushes the result to the same ref of `thesis-php/<name>`. Without `SPLIT_REMOTE` it only prints the commits:
that is how to try it locally. CI (`.github/workflows/split.yaml`) runs it on every push of a branch or a tag.
Why it works this way is in `docs/adr/0002-split-into-read-only-repositories.md`.

## Invariants that must not be broken

- A split is deterministic: the same commit of the monorepo gives the same commit of a package. The pushes are
  never forced, so a split that cannot fast-forward fails instead of rewriting a published history.
- Only what is committed at the ref is split (`git ls-tree`), never the working tree.
- A stable tag goes to a package only if `packages/<name>` changed since the previous tag of the same minor series
  (the tree hashes differ) and that tag is stable too. The first tag of a series, a pre-release and the first
  stable tag after a pre-release go to every package: otherwise a package would keep only an alpha
  while the others are stable, and `minimum-stability: stable` could not install it.

## Decisions

- splitsh-lite is built in `Dockerfile`: it needs libgit2 1.5 exactly (git2go v34), which Debian bookworm has
  and the Ubuntu of GitHub runners does not. The release binaries of splitsh stop at v1.0.1 (2017).
- `--scratch` on every run: the cache in `.git/splitsh.db` is keyed by the name of the ref, so a ref moved back
  gets the split of its old commit. Without the cache a split takes under a second.
- Shell, not `bin/monorepo`: it is a few git commands, and the image of splitsh has no PHP.
- Rejected: `git subtree split` (walks the whole history every time, slow as the history grows),
  actions that copy the files into a new commit (no history, no deterministic commits).
