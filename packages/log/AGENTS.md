# thesis/log

## Invariants that must not be broken

- A PSR-3 call reads its PSR-20 clock, casts a Stringable message and interpolates it on the calling fiber.
  The default `WallClock` returns without suspending. The queue keeps bounded snapshots of the message and context.
- Formatters run only in the writer task. The logger adds the line terminator and counts oversized or multiline
  formatter output as dropped. Console colors apply only to the level label. Escape ASCII controls in messages.
- Use `trySend()`. Full queues drop the incoming record. `$dropped` also counts accepted lines that the
  writer could not finish, including the line being written. Level filtering does not count as a drop.
- Recheck the writer task after capturing the record. A custom clock or Stringable conversion can suspend while
  the writer finishes. `trySend()` also detects a concurrent `close()`.
- The supplied group owns the writer. `close()` closes the queue and waits for the same task on every call.
  A cancelled wait does not cancel the writer. The logger borrows its destination and never closes it.
- The writer closes its queue before discarding pending records. A trace listener can log after the writer
  returns and before its task handle is marked done. The task returns a failure to `close()` so it does not
  cancel unrelated tasks.
  A daemon cancelled before it starts runs no cleanup, so `close()` also discards pending records after joining it.

## Decisions

- Use `psr/log` for callers and `Thesis\Log\Formatter` for the output. Built-in implementations live under
  `Thesis\Log\Formatter\`. NDJSON is the default. Rejected: a second logger interface and classes for every event.
  Applications can give recurring records typed helpers around PSR-3.
- The clock controls the timezone. Both built-in formatters preserve its offset.
- Select console colors explicitly with the formatter. A `Writer` can represent any destination, so its terminal
  support cannot be inferred from `STDERR` or `STDOUT`.
- A Stringable message supplies the message text. Casting it can run user code. Rejected: calling `__toString()`
  or serializing objects from the arbitrary PSR-3 context. A bad context value must not break the log call. Use
  a bounded type summary and snapshot exceptions under the PSR-3 `exception` key.
- Monolog is a development dependency. A comparison test checks that Stringable messages are cast before
  interpolation and referenced scalar fields stay in the context.
