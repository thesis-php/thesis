# Benchmarks

Read before writing or changing a benchmark, or a change justified by one. How to write a benchmark is in the
`README.md` of `phpstan/tally` (`vendor/phpstan/tally/README.md`).

## Rules

- A benchmark is a [Tally](https://github.com/phpstan/tally) benchmark in `packages/<name>/benchmarks/*Bench.php`,
  in `Thesis\<Name>\Benchmarks\`. Tally counts instructions, cycles and memory under Valgrind instead of measuring
  time, so the numbers are committed and a regression fails the run like a test. `benchmarks/` is not split:
  `.split` has only `src/` and the rest of `repositories.md`.
- The directory of a package is listed in `autoloadDev` of `monorepo.php` (`Thesis\Async\Benchmarks\`):
  the dependency analyser knows the code only through the autoloader, and Tally would be an unused dependency.
- `make bench P=<name>` counts and writes the results to `var/tally`, `make bench-accept P=<name>` makes them
  the expected numbers. `DRIVER=` chooses the Revolt driver as for the tests: `tally.php` keeps
  `REVOLT_DRIVER` among the variables that Tally passes to a case.
- The job `bench` of `check.yaml` runs `make bench` on one PHP and the default driver, and the release waits
  for it. Its results are the artifact `tally-results`: `gh run download <run> -p 'tally-*' -D var/tally`,
  then `make bench-accept`. Tally annotates and writes the job summary only when it sees `GITHUB_ACTIONS`:
  `make bench` passes the variables into the container and points the summary to `var/tally-summary.md`,
  because the summary file of the runner is outside the mount, and the job appends it.
- Prefer a complexity class (`#[Linear('count')]`, `#[Constant(...)]`) to expected numbers: it compares
  the data sets of one run with each other, so it holds on any machine, while `#[Expected]` numbers depend
  on the PHP build, the C library and the architecture (a Mac counts on aarch64, CI on x86_64).
- A benchmark of a complexity class has `#[Warmup(1)]`: the first call autoloads the classes, which makes
  the smallest data set expensive and hides the growth from it.
- A benchmark does not run on virtual time: it measures the real driver.
