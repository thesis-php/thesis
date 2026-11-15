# 4. Every package has versions of its own

Status: accepted

## Context

ADR 0002 gives all packages one version series starting at 2.0, above the tags of the older packages whose names
the monorepo reuses (`amqp` 1.0.2 and a branch `1.1.x`, `exceptionally` 0.3.0, `formatter` 0.1.0, `nats` 0.5.0).
So a package written a week ago would come out as 2.0.0 and claim a maturity it does not have, and below 1.0,
where every minor version breaks compatibility, a break in one package would bump the minor of all.
No tag of the monorepo exists yet, so nothing published has to change.

## Decision

1. **A series per package.** The manifest declares the current series of the package: `version: '0.1'`.
   It is changed by hand, in the commit that starts the series (a break below 1.0, a new minor above).
   `sync` writes it into the generated `composer.json` of the package as `extra.branch-alias`
   (`dev-main` is `0.1.x-dev`), merged with the `extra` of the manifest (`phpstan-extension`). The release reads
   it from the tree of a commit with `git cat-file`: a manifest of another commit cannot be run.
   `Config::$version` goes.
2. **Constraints from the series.** `'thesis/async' => '*'` is generated as the range of versions compatible
   with the series of the dependency: `^0.Y` below 1.0, `^X.0` above. A manifest may raise the lower bound
   (`^0.1.5`, `^2.1`) inside that range, `sync` fails outside it. A new minor of `amqp` 2.x keeps the range
   `^2.0` and changes no dependent. A new series of `async` 0.x changes the range in the `require` of every
   package that depends on it, and such a package starts a new series too: its old series stays installable
   with the old `async`. So a break cascades to the dependents, not to every package. A dependent above 1.0
   takes a new minor for it (`amqp` 2.1 on `async` 0.2): a user who calls `async` directly gets its break
   with a minor of `amqp`, which is the price of stable packages on dependencies below 1.0.
   A package that knows another one without requiring it (`phpstan-extension` knows the functions of `async`)
   is outside the cascade: a new series of `async` releases it too when its types change.
3. **One branch.** Only `main` of the monorepo is split, to `main` of every package, the default branch.
   The split of `*.*.x` branches and `v*` tags of ADR 0002 goes, with its triggers and its code:
   a series lives on `main` until the next one starts. There are no maintenance branches yet: a fix goes
   to the current series, and the branches of older series are designed with the first backport that someone
   needs.
4. **A release is a commit.** Every package has a `CHANGELOG.md` (Keep a Changelog), made with the package
   like the rest of it, and a release turns its `[Unreleased]` into `[0.1.4] - <date>` (or `[0.2.0-beta1]`).
   Several packages are released by one commit. A push of `main` releases the headings it added: a heading
   right under `[Unreleased]` whose version the changelog had not before the push. The package is released
   as it is at the head of the push. Nothing else is released on a push, so a release that failed is never
   retried with somebody else's later work: it is repaired by a re-run of its job or by `workflow_dispatch`
   (item 8). A push that starts a series of a package that others require also releases its first version:
   until then no dependent can be released (item 5).
5. **The headings of a push are released only if, for each of them,**
   - `[Unreleased]` of the package is empty: a listed change is not part of this version,
   - the version belongs to the series of the manifest and comes right after the last tag of that series
     in the split repository (with or without `v`, composer reads them as one version): the next patch,
     the next pre-release or the stable version of the last one, and `X.Y.0` or its first pre-release
     for a new series. A typo like `0.1.14` for `0.1.4` is refused instead of published for good,
   - every tag of that series or above in the split repository is at an ancestor of the split being released,
     and the repository has no branch of the series (`0.1.x`): what the split did not write is an older package
     of the same name, and a version or a `0.1.x-dev` of the series would collide with it,
   - the repository is a split under its own name (`Repositories::isSplit()`),
   - every dependency of `require` on a monorepo package that the previous stable release of the series has too
     has the same generated range (item 2), whatever its lower bound,
   - every monorepo package in `require` is, in the files it ships (`.gitattributes`), the same as its highest
     tag in the range, or is released by the same push: the tests ran against its head, and a user installs
     a tag,
   - every lower bound of `require` on a monorepo package is met by an existing stable tag or by a heading
     of the same push, stable for a stable release: a stable version that needs a beta cannot be installed
     without `minimum-stability`. `require-dev` is not checked, it is not installed with the package.

   Everything is decided before anything is pushed, and a push is released whole or not at all. A tag
   of the version that exists already at the split being released counts as released, and the release goes on
   with the steps after it, so a re-run continues a release that failed halfway. A tag at any other commit fails.
6. **The steps, per package, dependencies first**: the lightweight tag `vX.Y.Z` at the split of the head,
   pushed to the split repository (the push carries its commits, `main` there is left to the split job),
   then its GitHub Release with `target_commitish` set to that commit (GitHub tags the default branch
   otherwise). A step that exists already counts as made, a tag compared by the commit it points to:
   two runs may release the same headings at once.
7. **Releases on GitHub, in the split repository.** The body is the section of the changelog, `prerelease`
   is set for `-alphaN`, `-betaN`, `-RCN`, and `make_latest` is always sent (GitHub takes `true` otherwise),
   `true` only for the highest stable version of the package. A user subscribes to the releases
   of the repository of the package (Watch, `releases.atom`), and Dependabot and Renovate take their notes
   from there: it is the source of the package on Packagist.
8. **The workflow.** The release is a job of the workflow of the checks and `needs` them. A run of a push
   of `main` is in no group shared with another run (a group per `run_id`): a pending run of a group
   is replaced by a newer one, its release with it. Only a pull request cancels its older runs.
   `workflow_dispatch` on `main` takes a commit reachable from `main` and the packages to release.
   It does not run the checks again: the image and the tools float, so an old commit may fail checks it passed.
   It requires a successful run of the checks for that commit instead, and releases the headings of those
   packages at that commit with the tooling of the head of `main`. The release needs the history
   of the monorepo (`fetch-depth: 0`).
9. **A new series is above every older version and branch of its name**: new packages start at 0.1,
   `amqp` at 2.0, `exceptionally` at 0.4, `formatter` at 0.2, `nats` at 0.6. Item 5 refuses a release
   that breaks it, so the older tags and branches never collide with ours and stay as they are,
   with their history: Packagist keeps the old versions and the locks that point to their commits keep working.
10. **Taking over an older repository** is done once, by hand: its own rulesets are deleted, its open issues
    are moved or closed (`github` turns the issues off), branches that are still needed are merged or moved
    to the monorepo, and the rulesets of the split are added. Nothing is force-pushed: the split creates `main`
    where it is missing and fast-forwards it where it pushed already (`amqp`, `exceptionally`).
    The read-only ruleset covers every branch and tag, so the older series end there: they are installable
    as before and get no more commits or tags. The split pushes nothing to a repository without the rulesets
    of the split or to a name that redirects to a renamed one (`Repositories::isSplit()`).

The read-only repositories and the app of ADR 0002 stay as they are.

## Consequences

- A series started by mistake is undone by a commit while it is unreleased: it changes only the alias of `main`.
  A series of a package that others require cannot stay unreleased (item 4).
- A forgotten new series of a dependent fails the release, not the push: `main` of a package may hold commits
  that need a newer series of a dependency than its alias says, until the next release.
- A release of a package releases the dependencies it has changed since their tags (item 5), so a fix
  across packages goes out together.
- A version is chosen per package and release, and its changes are written in its own changelog,
  where a user of the split repository reads them.
- Stable 0.x versions need no `minimum-stability`, a pre-release is made only where it is needed.
- The root `composer.json` replaces every package with its series (`0.1.x-dev`), not `self.version`:
  the root is `dev-main`, which no `^0.Y` of an external dependency would accept.
- The release assumes the package is registered on Packagist (ADR 0002, item 4), which is not automated yet.
- The tests run with the newest version of every dependency, never with the lowest one that a range allows.
  The job of ADR 0002 that installs every package alone with its lowest dependencies is still to be added.

## Rejected

- **One series for all packages** (ADR 0002): new packages at 2.0, and a break of one bumps all of them below 1.0.
  A shared series above every older tag and branch would have to start at 1.2 and promise stability nobody gives.
- **Shared series for groups of packages**: two release mechanisms instead of one.
- **No `main` in split repositories, a series is a branch**, as in the older repositories. Every review of it
  found a new way to lose commits or block the split: the last commits of a series had to be pushed to its branch
  from the history at every change of the series, the ranges of a dependent had to be checked against that branch
  by the split, a series undone by hand came back, and the default branch switched with every series.
  With `main` a series is one line of an alias until it is released.
- **Maintenance branches now** (`<name>/X.Y.x` split to `X.Y.x`): the most fragile part of every review.
  A pushed branch runs the workflow and the tooling of its own old commit, the alias of `main` collides
  with a branch of its series, the commit to start from is ambiguous (a split commit is shared by every commit
  that leaves the package as it is), and a backport across packages is copied once per branch. Below 1.0
  nobody has asked for one yet.
- **Releasing the top heading of every package on every push**: a release that failed would be retried
  at the head of the next push, with its work in the tag, and every push would ask GitHub about every package.
- **The release after the split job, or pushing `main` of the split repository itself**: the split job fails
  when any package fails and a replaced run of it ends as cancelled, and a second writer of `main` races
  with the split. The pushed tag carries the commits.
- **Releasing the headings that pass and stopping only the others**: everything is decided before the first push
  anyway, the repair is the same dispatch, and a half-released push is harder to reason about.
- **Running the checks again on a dispatch**: the image and the global tools float, so a commit that passed
  could fail for reasons it does not have.
- **A version higher than the last tag** instead of the next one: a typo would publish a version for good.
- **Tags of the monorepo** (`<name>/vX.Y.Z`): nothing needs them without maintenance branches.
- **Releasing the commit that added the heading** (`git blame`): a fix on the same day does not change the line,
  and a later edit of the line moves it away from the tag.
- **Tags pushed by hand**: the version would be written twice, in the tag and in the changelog, and a tag
  is public before anything checks it. GitHub also starts no workflow for a push of more than three tags.
- **Leaving the older branches out of the read-only ruleset**: an exception per package in the manifests
  for series that nobody maintains.
- **Changesets** (a note per change, collected into a release): a second place for what `[Unreleased]` holds.
- **Releases in the monorepo**: one feed of every package retelling their changelogs, and not the repository
  that Packagist, Dependabot and the users of a package look at.
- **Importing the history of an older repository into the monorepo** to continue its branches: the split drops
  signatures, so the commits of GitHub's web interface get other ids anyway, and the monorepo gets foreign history.
