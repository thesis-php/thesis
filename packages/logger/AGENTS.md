# thesis/logger

## Invariants that must not be broken

- A PSR-3 call reads its PSR-20 clock, casts a Stringable message and interpolates it on the calling fiber.
  Copy the clock's `DateTimeImmutable` before casting the message. Calling its constructor again changes the
  original object, and a subclass can override `format()`. The default `WallClock` returns without suspending.
- The stream handler borrows its destination and writes the formatted record followed by a newline.
  Bind its recursion guard around capture, formatting and output so independent producers can still log.
  Console colors apply only to the level label. Escape ASCII controls in messages.

## Decisions

- Keep built-in output direct while the policy for diagnosing lost buffered records is undecided.
  The original queue and writer are in `8d2d397:packages/logger/src/Logger.php`.
  The local stash `Direct logger output and optional buffered handler` contains the previous implementation at
  `e5679508^3:packages/logger/src/Logger/BufferedHandler.php`.

- The package exposes `Thesis\Logger` through `rootEntry`. Its formatters and record type live in
  `Thesis\Logger\`.

- Use `psr/log` for callers and `Thesis\Logger\Formatter` for the output. Built-in implementations live under
  `Thesis\Logger\Formatter\`. NDJSON is the default. Rejected: a second logger interface and classes for every event.
  Applications can give recurring records typed helpers around PSR-3.
- Track substituted keys from the original message. A replacement value can itself contain a placeholder.
  Console filtering keeps the normalized record context intact.
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
