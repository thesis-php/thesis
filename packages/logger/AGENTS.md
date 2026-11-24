# thesis/logger

## Invariants that must not be broken

- A PSR-3 call reads its PSR-20 clock, casts a Stringable message and interpolates it on the calling fiber.
  Copy the clock's `DateTimeImmutable` before casting the message. Calling its constructor again changes the
  original object, and a subclass can override `format()`. The default `WallClock` returns without suspending.
  The queue keeps bounded snapshots of the message and context.
- Formatters run only in the writer task. The logger adds the line terminator and counts oversized or multiline
  formatter output as dropped. Console colors apply only to the level label. Escape ASCII controls in messages.
- Use `trySend()`. Full queues drop the incoming record. `$dropped` also counts accepted lines that the
  writer could not finish, including the line being written. Level filtering does not count as a drop.
- Drop calls to the same logger from its writer context, including during a queue receive. A formatter, output
  or trace listener can otherwise enqueue another record on every write or every wait. Other producers can
  log while the writer waits, and the writer can log to a different logger. Bind the guard when spawning the writer
  so its lifecycle events and those of its children inherit it.
- Recheck the writer task after capturing the record. A custom clock or Stringable conversion can suspend while
  the writer finishes. `trySend()` also detects a concurrent `stop()`.
- The supplied group owns the writer. `stop()` closes the queue and waits for the same task on every call.
  A clone would share the queue and task but copy the failure and drop count, so the logger cannot be cloned.
  A cancelled wait does not cancel the writer. The logger borrows its destination and never closes it.
- The writer closes its queue before discarding pending records. A trace listener can log after the writer
  returns and before its task handle is marked done. The writer stores a formatting or output error in `$failure` before cleanup.
  It returns the error to `stop()` so unrelated tasks can continue.
  A daemon cancelled before it starts runs no cleanup, so `stop()` also discards pending records after joining it.

## Decisions

- The package exposes `Thesis\Logger` through `rootEntry`. Its formatters and record type live in
  `Thesis\Logger\`.

- Use `psr/log` for callers and `Thesis\Logger\Formatter` for the output. Built-in implementations live under
  `Thesis\Logger\Formatter\`. NDJSON is the default. Rejected: a second logger interface and classes for every event.
  Applications can give recurring records typed helpers around PSR-3.
- The clock controls the timezone. Both built-in formatters preserve its offset.
- Select console colors explicitly with the formatter. A `Writer` can represent any destination, so its terminal
  support cannot be inferred from `STDERR` or `STDOUT`.
- A Stringable message supplies the message text. Casting it can run user code. Rejected: calling `__toString()`
  or serializing objects from the arbitrary PSR-3 context. A bad context value must not break the log call. Use
  a bounded type summary and snapshot exceptions under the PSR-3 `exception` key. Read the backing fields:
  even final throwable getters can invoke property hooks, magic access or a message object's string conversion.
- Skip context keys with malformed UTF-8. JSON substitutes the replacement character for malformed bytes,
  which can turn two distinct keys into duplicate object members.
- Monolog is a development dependency. A comparison test checks that Stringable messages are cast before
  interpolation and referenced scalar fields stay in the context.
