# 3. Packages are split by bin/monorepo

Status: accepted

## Context

ADR 0002 splits every `packages/<name>` with splitsh-lite. splitsh links libgit2 through git2go v34, which needs
libgit2 1.5 exactly: neither the runners of GitHub nor the PHP image have it, and splitsh has no binaries
of its recent versions. So CI built it from source in a Debian image on every push, which took 53 of the 79 seconds
of the job, while the split and the pushes took 6.

## Decision

`bin/monorepo split` (`tools/monorepo`, `make split`) replaces splitsh-lite and `tools/split`. It reads the whole
history once for all packages with git plumbing, computes the ids itself and writes the commits as loose objects.
It keeps everything else of ADR 0002: the history of the directory, deterministic commits, pushes that are never
forced, the rules for tags. It gives the ids splitsh gave for the history so far, so the switch rewrote nothing.

## Consequences

- The split runs in the PHP image of the other commands and has the tests and the checks of the monorepo tooling.
- How a split commit is written is ours to keep stable: a change to it rewrites every split repository.
- A package removed and added again continues its history, a merge where only one side changed the package
  is not copied. Here the ids may differ from what splitsh would give, which no longer matters.
- A `v*` tag must be a version of ADR 0002 (`vX.Y.Z`, `vX.Y.Z-alphaN`, `-betaN`, `-RCN`): any other one
  fails the split instead of being compared by a guess.

## Rejected

- **A fork of splitsh with a prebuilt image**: still cgo pinned to libgit2 1.5, plus an image to publish.
- **A tool of our own in Go**: a toolchain to distribute for what is a few git commands and a loop.
- **Caching the image of splitsh in CI**: faster on a hit, the same dependency on an unmaintained binding.
