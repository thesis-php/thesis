# Hot reload

Draft for phase 4 of the [roadmap](roadmap.md). The first version is for local development.

## First version

After the server crashes, saving a fix starts it again in the same dev session. This applies when the
first launch fails, when a reload introduces an error, and when an already running server crashes.

The old `thesis/hot-reload` exited with status 255 when a replacement hit a PHP parse error. The next
save had no effect because the watcher had exited too. The old implementation could also start the
replacement before its predecessor released a resource, and missed an atomic replacement with the same
`mtime`. These are separate cases to check against the new implementation.

- A supervisor starts the file watcher before the first launch and keeps it running for the whole dev
  session. The application runs as a direct child through `Process::start()` from
  [`thesis/process`](../packages/process/README.md). The supervisor keeps the child's stderr visible.
- When the child exits on its own, report its exit code or signal and wait for another relevant save.
  A failed launch also leaves the watcher running. The next relevant save triggers another attempt.
  The supervisor waits for that save without retrying on a timer.
- When a change requests a restart, send `SIGTERM` and wait up to a deadline. If the child is still
  running, send `SIGKILL`. Use `Process::wait()` to confirm its exit before starting the replacement.
  Changes received during shutdown feed the upcoming launch. Changes received after that launch begins
  can request one more pass. Coalesce closely spaced events from the same save.
- `SIGINT` and `SIGTERM` end the dev session. Stop scheduling launches and shut down and reap the
  child, if there is one.

The [CLI](../packages/hot-reload/README.md) accepts both manual `SIGHUP` and file changes via `--watch`.
Try it on the dev project and measure how long it takes to scan the paths that developers edit.

## Acceptance

- Start a server and get a successful HTTP response. Save invalid PHP and see its parse error. Save a fix
  and get a response from the new code without restarting the supervisor. Repeat with invalid PHP already
  on disk at the first launch and with a runtime crash after a successful launch.
- A child that holds the port while shutting down exits before its replacement starts. Check a child that
  ignores `SIGTERM` too. Save a fix while shutdown or startup is in progress and verify that it takes effect.
- Stop the dev session while a child is running and while the supervisor is waiting after a crash. Verify
  that the child is reaped and no restart is scheduled.

## Later

- If losing the running version during a bad edit is disruptive, try `php -l` on changed PHP files before
  stopping the old process. Keeping the old server available through a failed application boot would need
  a readiness check and a way to switch traffic after the replacement is ready.
- Revisit PhpStorm when there is a dev container for the application. Start with a shared Docker Compose run
  configuration and Xdebug path mappings. Verify that breakpoints work again after a failed start and a fix.
  Consider an IDE plugin if the built-in configuration falls short of the workflow we want.
