# Tests

Read before writing or changing a test, or running mutation testing (`make infect`). How to use Testo is in its
skills (`testo-*`), a test that depends on the order of callbacks is in `fibers.md`.

## Rules

- Write the failing test first, then the fix.
- `make infect P=<name>` kills every mutant of a package with `mutationTesting` (on by default) in its manifest:
  `sync` writes them to the matrix of the job `infect` of `infection.yaml`, which runs nightly and on manual dispatch.
  A surviving mutant gets a test. A mutant that shows code to be redundant gets the code simplified. Only one
  that no input tells from the code (most often it only makes the code slower) is skipped by a comment
  above the statement it mutates: the reason, then `// @infection-ignore <Mutator>, ...`. An annotation covers
  everything inside the statement, so above a loop or an `if` with more in it the comment goes before
  the expression: `/* @infection-ignore LessThan */ $shift < 64`. A misspelt mutator skips nothing,
  Infection only warns, so the mutant still fails the run.
- `make infect` of several packages runs in parallel: the files of a run (coverage, mutants) are in `/tmp`
  of its container. Not in one container of `make t`: its `/tmp` is shared.
  Trap: a `tmpDir` in the config (it was `var`) is shared by every run, and Infection deletes all of it at the end
  of a run, the coverage of the others too. Every run takes `--threads=max`, so two at once share the cores:
  a mutant that runs slower than `timeout` counts as killed, check the timeouts of such a run alone.
- Trap: on x86_64 Xdebug 3.6.0alpha1 of the PHP 8.6 image (8.6.0) dies with signal 11 in the first test
  of the initial run of `make infect`, on every package. It is the per-test coverage that `--coverage-xml`
  turns on: `XDEBUG_MODE=coverage vendor/bin/testo run --coverage --coverage-xml=/tmp/cov --suite=formatter`
  crashes, the same without `--coverage-xml` passes, on any `--coverage-level`. aarch64 passes (a Mac),
  so the job `infect` runs on `ubuntu-24.04-arm`; reproduce with `docker run --platform linux/amd64`.
  Move it back to `ubuntu-latest` once a release of Xdebug passes there.
- For now the job `infect` has `continue-on-error: true`: the rewrite
  of the event loop left surviving mutants in async (120 on CI, MSI 86 %), net, `Stream` of io and async-trace.
  Kill them, then make the workflow fail on a surviving mutant.
- The mutation score is also the badge of a package (`tools/monorepo/AGENTS.md`). `make infect P=<name>` writes
  the summary of the run to `var/infection/<name>.json`, and on a run of `main` the job `infect` puts its `msi`
  to the module `<name>` of the project `github.com/thesis-php/thesis` in the Stryker Dashboard, also when
  Infection has failed on a surviving mutant: the badge shows the score there is. It needs
  `secrets.STRYKER_DASHBOARD_API_KEY` of the monorepo, the key of the repository enabled
  at dashboard.stryker-mutator.io, and fails without it. Only the score is sent: the report with the mutants
  is built by Infection only inside its own upload.
- Open: Infection generates no mutants in a file of plain functions (`src/api.php`, `src/Internal/internal.php`),
  only in classes: all 73 mutants of time are in `IsoInterval`, all 19 of exceptionally in `ErrorHandler`
  (`--logger-text=var/mutants.txt --log-verbosity=all` lists them). It once did, hence the `@infection-ignore`
  in `timestamp()` of time. Until it does again, `make infect` says nothing about functions: go through
  the mutants of a changed one by hand (a bound, a sign, a dropped statement) and check that a test fails.
- Coverage is for the badge of a package (`tools/monorepo/AGENTS.md`), not a gate: no target, no statuses
  and no comments of Codecov. The job `coverage` of `check.yaml` runs `make coverage P=<name>` for every package
  with `tests/` (the matrix is written by `sync`) and uploads its Clover report with the flag `<name>`.
  `make coverage` starts RabbitMQ only for the AMQP integration tests so a broker startup failure cannot
  block coverage of the other packages.
  The report of one suite lists the `src/` of every package, so the component `<name>` of `.github/codecov.yml`
  takes the flag and `packages/<name>/src/**` together: a package is covered by its own tests, not by those
  of the packages that use it. Trap: one run of every suite with coverage exhausts 2 GB in Xdebug,
  a run per package takes at most a minute. On ARM like `infect`: the suites were checked under coverage
  only there. The upload needs `secrets.CODECOV_TOKEN` of the monorepo or tokens turned off for public
  repositories in the settings of the organization in Codecov. Trap: the default branch of the monorepo
  in the settings of Codecov must be `main` (Codecov creates a repository with `master`). The badge reads
  the components from the config Codecov has saved for the repository, and it saves the config only of an upload
  on the default branch: otherwise the API shows the coverage of a component and its badge says `unknown`.
- No traits for test helpers: plain functions (in `tests/helpers.php`) or a base class.
- A failure the test goes on after is taken by `thrown()` of `tests/helpers.php` of the package, the same
  in each (it fails the test if nothing is thrown), and checked with `Assert::instanceOf()`, not by `try`,
  `Assert::fail()` and `catch`. When the exception is the only outcome, it is `Expect::exception()`.
- That a task still waits is `Assert::false($task->isDone)` after a pause, not a flag the task sets at its end:
  the flag takes a closure around the call and a capture by reference.
- A closure that writes a variable of the test captures it by reference, and so does every closure on its way:
  `static function () use (&$called)`, never `static fn()` around it. An arrow function captures by value,
  the write is lost, and an assertion like `Assert::false($called)` passes on a broken library.

## Decisions

- Rejected: skipping mutants in the manifests (`InfectionSkip`, generated into `infection.json5`).
  Infection could skip only a whole method or a line found by its text in every class, and `sync` had to find
  the method and the line to fail on a stale skip. An annotation of Infection 0.35.10 names the mutators
  of one statement, sits next to the reason, and leaves with the code.
- Rejected: the upload to the Stryker Dashboard by Infection itself (`logs.stryker` of `infection.json5`).
  Infection 0.35.12 sends a report to the project and the branch without `?module=`, so the packages of the matrix
  would overwrite one score, and it reads the branch and the key from the environment of CI, which the container
  of `make` does not have.
- Rejected: moving the files of the other tools from `var` to the container's `/tmp`, where Infection keeps its files.
  Infection keeps there only the files of one run and deletes them at its end. The caches of PHPStan, Rector
  and PHP CS Fixer (`var/phpstan`, `var/rector`, `var/php-cs-fixer.cache`) live between runs, and `/tmp` dies
  with the container of `--rm`: every run would start cold. Parallel runs only overwrite each other's cache.
- Rejected: `TextLog` of thesis/async-trace in the tests of the packages built on async (cache, io, net, amqp).
  It fits a test of the scope tree itself: who has cancelled whom, where a task waits. These tests check results
  (values, bytes, frames). io, net and most of amqp run on real time, where the seconds of a line differ
  on every run. On virtual time the log holds the scopes and waits inside the package (the shields
  and `synchronizedKey()` of `AtomicCache`), so a refactoring there would fail the test, and the seed
  of `VirtualTimePlugin` reorders the lines of a tick. One line of it is no shorter than `Instant::elapsed()`.
