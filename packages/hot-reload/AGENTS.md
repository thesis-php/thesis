# Hot reload

## Invariants that must not be broken

- The CLI selects the watcher. Without watched paths, NullWatcher starts no task.
- The signal subscription and file watcher belong to the dev session. Take the first snapshot before starting
  the worker. Child exit reporting has its own task so a failed launch leaves reload requests active.
- File changes and SIGHUP post to the same one-slot channel. After the old worker exits, drain the pending
  request and refresh the shared snapshot without suspending before launching its replacement. This includes
  saves during shutdown that polling has yet to see. Changes after that snapshot can request another reload.
- The watcher waits for the configured duration after the most recent detected edit on the async monotonic clock.
  Scan again at the deadline so an edit before the next regular poll resets the wait. SIGHUP requests a reload immediately.
  Refreshing the snapshot for a launch clears pending edits already covered by that launch.
- The CLI traps SIGHUP, SIGINT and SIGTERM until the supervisor group has joined. Restoring the default signal
  actions while the worker is stopping can kill the supervisor before it reaps the worker.
  SIGINT or SIGTERM ends the group and closes the reload channel. A shielded group owns the worker until it exits.
  Start a replacement only after the worker exits.

## Decisions

- Rejected: polling file paths and `mtime` alone. An atomic replacement with the same timestamp changed the
  code without changing that snapshot. The watcher compares paths and hashes of file contents.
