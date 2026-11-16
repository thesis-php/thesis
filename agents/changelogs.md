# Changelogs

Read before writing or editing a `CHANGELOG.md` of a package. How a heading becomes a release is in `releases.md`.

## Rules

### Form

- Every package has a `CHANGELOG.md` in the format of [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/),
  made with the package like the rest of it: the header of `packages/async/CHANGELOG.md`, then `## [Unreleased]`,
  then a section per version, the newest first. No link references under the headings: the body of a GitHub
  Release is the section, and the tags are in the split repository.
- A release turns `[Unreleased]` into `## [0.1.4] - YYYY-MM-DD` (or `[0.2.0-beta1]`, `-alphaN`, `-RCN`)
  above a new empty `## [Unreleased]`, and the push releases it (`releases.md`).
- A section has the groups of Keep a Changelog in its order, only those it needs: `### Added`, `### Changed`,
  `### Deprecated`, `### Removed`, `### Fixed`, `### Security`. In a group the breaks go first,
  each marked `**BC break:**`.
- A paragraph right under the version heading is only for what concerns the whole package
  (it moved to the monorepo, it was written anew): `packages/amqp/CHANGELOG.md`.
- A package that was never released has only `### Added`, with a line per main feature.

### Content

- An entry is one line, two at most: what a user of the package sees changed, with the symbol in backticks.
  A break adds what to do instead (`Pass its name.`, `Use (string) $type.`). Not why it was done, how it works
  inside, every limit and edge case or the story of the bug: that is in the commits, the docs and `AGENTS.md`.
  `formatValue() stays short for any value: long strings, arrays and deep nesting are cut.`, not the numbers
  of every cut.
- Only what a user can notice is listed: no refactoring, tests, tooling or generated files.
- `[Unreleased]` is the difference from the last release, not a log of commits: a change to something
  that is not released yet rewrites its entry instead of adding one. "Changed X" and then "Removed X"
  leave only "Removed X", "Added X" and then "Changed X" leave "Added X" as it is now, "Added X"
  and then "Removed X" leave nothing.

## Decisions

- Rejected: changesets (a note per change, collected into a release): a second place for what `[Unreleased]` holds.
