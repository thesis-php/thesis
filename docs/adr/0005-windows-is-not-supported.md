# 5. Windows is not supported

Status: accepted

## Context

On Windows `select()` sees only sockets: a pipe or a file is always reported ready, and no Revolt driver but uv
can watch one. So stdio cannot be non-blocking, a child process needs its stdio proxied over sockets
(amphp ships a C wrapper, `windows-process-wrapper.exe`, for it), PHP has no `unix://` sockets, no `pcntl`
for signals, and DNS servers are in the registry instead of `/etc/resolv.conf` (amphp reads them with `reg query`
through its `process`). Everything in CI runs in Linux containers through `make`, which a Windows runner of GitHub
cannot start, so a Windows job would be a native setup of its own. Asynchronous PHP is rarely run on Windows
in production, and developers on Windows mostly work in WSL2 or Docker, where the code runs on Linux.

## Decision

1. Windows is not supported, and nothing is guaranteed to work there. A user who needs it opens an issue,
   and the decision is revisited by a new ADR.
2. The code is written for Unix only: no `PHP_OS_FAMILY` checks, no Windows branches, no Windows paths.
3. `thesis/async`, which every package with I/O requires, triggers `E_USER_WARNING` when it is loaded on Windows,
   so a user learns it at the first run instead of from a broken feature. Not an exception: `vendor/autoload.php`
   is loaded by PHPStan, IDE tools and composer scripts, which would fail on a machine that only opens the project.

## Consequences

- `stdio()` of `io` loses its Windows check, the warning of `async` covers it.
- `net` keeps Unix sockets, `socketPair()` on `STREAM_PF_UNIX` and the DNS configuration from `/etc/resolv.conf`.
  `process`, `parallel` and `cluster` are designed for Unix only.
- An error handler that turns warnings into exceptions (Symfony in dev) makes the warning fail the application
  on Windows. For a platform that is not supported this is acceptable.
- `thesis/time` has no I/O and does not warn.

## Rejected

- **Supporting the network core** (`async` without signals, `io` without stdio, `net` over TCP and TLS):
  a Windows job in CI outside Docker, a DNS configuration from the registry, a Windows branch of `socketPair()`,
  explicit exceptions in every Unix-only feature, for an audience that barely exists.
- **Requiring `ext-posix` to fail `composer install` on Windows**: Composer cannot declare an operating system,
  the error would name an extension nobody uses, `--ignore-platform-reqs` skips it, and some distributions
  package posix separately.
- **An exception on load**: it breaks the tools that load the autoloader without running the code.
