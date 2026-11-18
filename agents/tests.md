# Tests

Read before writing or changing a test, or running mutation testing (`make infect`). How to use Testo is in its
skills (`testo-*`), a test that depends on the order of callbacks is in `fibers.md`.

## Rules

- Write the failing test first, then the fix.
- `make infect P=<name>` kills every mutant of a package in the matrix of the job `infect` of `check.yaml`.
  A surviving mutant gets a test. A mutant that shows code to be redundant gets the code simplified. Only one
  that no input tells from the code (most often it only makes the code slower) is skipped by a comment
  above the statement it mutates: the reason, then `// @infection-ignore <Mutator>, ...`. An annotation covers
  everything inside the statement, so above a loop or an `if` with more in it the comment goes before
  the expression: `/* @infection-ignore LessThan */ $shift < 64`. A misspelt mutator skips nothing,
  Infection only warns, so the mutant still fails the run.
- Trap: on x86_64 Xdebug 3.6.0alpha1 of the PHP 8.6 RC7 image dies with signal 11 in the first test
  of the initial run of `make infect`, on every package. It is the per-test coverage that `--coverage-xml`
  turns on: `XDEBUG_MODE=coverage vendor/bin/testo run --coverage --coverage-xml=/tmp/cov --suite=formatter`
  crashes, the same without `--coverage-xml` passes, on any `--coverage-level`. aarch64 passes (a Mac),
  so the job `infect` runs on `ubuntu-24.04-arm`; reproduce with `docker run --platform linux/amd64`.
  Move it back to `ubuntu-latest` once a release of Xdebug passes there.
- For now the job `infect` has `continue-on-error: true` and is out of `needs` of the release: the rewrite
  of the event loop left surviving mutants in `Stream` of io and in async-trace. Kill them, then make it block again.
- No traits for test helpers: plain functions (in `tests/helpers.php`) or a base class.
- A closure that writes a variable of the test captures it by reference, and so does every closure on its way:
  `static function () use (&$called)`, never `static fn()` around it. An arrow function captures by value,
  the write is lost, and an assertion like `Assert::false($called)` passes on a broken library.

## Decisions

- Rejected: skipping mutants in the manifests (`InfectionSkip`, generated into `infection.json5`).
  Infection could skip only a whole method or a line found by its text in every class, and `sync` had to find
  the method and the line to fail on a stale skip. An annotation of Infection 0.35.10 names the mutators
  of one statement, sits next to the reason, and leaves with the code.
