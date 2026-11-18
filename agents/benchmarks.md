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
  the expected numbers.
- The job `bench` of `check.yaml` runs `make bench` on one PHP, and the release waits
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
- Trap: `tally_hooks` of Tally 1.0.1 is built for the module API of PHP 8.6 before RC7 (20260924), the image has
  RC7 (20261112), and every case fails with "PHP did not load Tally's tally_hooks extension". Until a release
  of Tally is built for it, build the extension in the container as root (`apk add bash $PHPIZE_DEPS`, then
  `vendor/phpstan/tally/build/build-tally-hooks.sh var/tally-hooks/tally_hooks.so`) and load it by an ini file
  in a directory of `PHP_INI_SCAN_DIR` (`/usr/local/etc/php/conf.d:/workspace/var/tally-ini`), which `tally.php`
  passes to the cases. In CI the job `bench` is off (`if: false`) and out of `needs` of the release
  for the same reason: turn it back on with the release of Tally.
