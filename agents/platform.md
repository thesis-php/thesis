# Platform: 64-bit Unix

Read before writing code that depends on the operating system or on the size of `int`.

## Rules

- Every package is 64-bit only: `platform` of `monorepo.php` (`php-64bit`) is required by every package,
  a manifest does not repeat it. Code relies on 64-bit `int` (nanoseconds, `pack('J')`) without checking
  `PHP_INT_SIZE`.
- Windows is not supported, and nothing is guaranteed to work there. The code is written for Unix only:
  no `PHP_OS_FAMILY` checks, no Windows branches, no Windows paths. `net` keeps Unix sockets, `socketPair()`
  on `STREAM_PF_UNIX` and the DNS configuration from `/etc/resolv.conf`. `process`, `parallel` and `cluster`
  are designed for Unix only.
- The only `PHP_OS_FAMILY` check: `src/platform.php` of `thesis/async`, which every package with I/O requires,
  triggers `E_USER_WARNING` when it is loaded on Windows, so a user learns it at the first run instead of
  from a broken feature.
  `thesis/time` has no I/O and does not warn. An error handler that turns warnings into exceptions (Symfony in dev)
  makes it fail the application on Windows, which is acceptable for a platform that is not supported.
- A user who needs Windows opens an issue, and the decision is revisited here.

## Decisions

- Why no Windows: there `select()` sees only sockets (a pipe or a file is always ready, only the uv driver
  can watch one), so stdio cannot be non-blocking and a child process needs its stdio proxied over sockets
  (amphp's `windows-process-wrapper.exe`). PHP has no `unix://` sockets and no `pcntl` there, DNS servers
  are in the registry. CI runs in Linux containers through `make`, which a Windows runner cannot start.
  Asynchronous PHP rarely runs on Windows in production, and developers there work in WSL2 or Docker.
- Rejected: supporting the network core (`async` without signals, `io` without stdio, `net` over TCP and TLS):
  a Windows job outside Docker, DNS from the registry, a Windows branch of `socketPair()` and explicit exceptions
  in every Unix-only feature, for an audience that barely exists.
- Rejected: requiring `ext-posix` to fail `composer install` on Windows: Composer cannot declare an operating
  system, the error would name an extension nobody uses, `--ignore-platform-reqs` skips it, and some distributions
  package posix separately.
- Rejected: an exception on load: `vendor/autoload.php` is loaded by PHPStan, IDE tools and composer scripts,
  which would fail on a machine that only opens the project.
