# thesis/io

Fast binary I/O: `Buffer` with typed big/little-endian encoding and varints, the `Reader`, `Writer`, `Closer`
interfaces, and the streams: `Stream` over a non-blocking PHP resource, and `stdin()`/`stdout()`/`stderr()`.
Streams wait through thesis/async.
The API and the design decisions are described in `README.md` — read it first.

This file is for agents changing this package. It is not loaded while an agent works in another package,
so how to *use* this package belongs in `README.md` or in a skill under `skills/<skill-name>/SKILL.md`.

## Invariants that must not be broken

- `Buffer` is the hot path of every binary protocol: a change to it is justified by a benchmark, not by taste.
  Its micro-optimizations (properties with defaults instead of `readonly`, bounds checks folded into the byte fetch,
  `chr()` instead of `pack()` for 1-2 bytes, `clone` in `slice()`) are measured: keep them and their comments.
  `slice()` is `clone $this` and assignments, not `clone($this, [...])`: the array of `clone()` makes it 33-43% slower.
- Every read is O(1): the cursor advances, consumed bytes are discarded only in `fill()`.
- A failed read throws `UnexpectedEof` and leaves the cursor where it was.
- Range checks of written values are `assert()` only, never a runtime `if`: they cost nothing in production.
- 64-bit PHP is required (`php-64bit`): `int` is int64. `readUint64()` throws `\OverflowException` above
  `PHP_INT_MAX`, varints keep the raw 64-bit pattern.
- Marks of `reserve()` are absolute positions, invalidated by `fill()`, `flush()` and `drain()`.
- `Reader::read()` returns 1 to `$max` bytes, throws `UnexpectedEof` at the end, `ReadFailed` on any other failure
  (a reset is not an end: a protocol may treat an EOF between messages as a clean close) and an `IoException`
  of its own once this side has closed it (`StreamClosed`, `SocketClosed`). `Writer::write()` writes all bytes,
  `Closer::close()` is idempotent.
- A read that throws, `Cancelled` included, has taken nothing: `Buffer::fill()` loses no bytes only because of it.
  A decorator keeps it too: it changes its state only after the inner read has returned.
  A write that throws after writing a part leaves its writer unusable (`Stream` closes itself):
  a retry would duplicate bytes or leave a hole. Only stdio, which has no messages, goes on after the half.
  `Buffer::flush()` puts the bytes back on a throw, before those written meanwhile: the drain comes first,
  since bytes appended by another fiber during the write must not be written or lost.
- `Buffer`, `Order` and the interfaces call nothing of thesis/async: only streams that wait do
  (the interfaces name `Cancelled` in their PHPDoc only).
- `Stream` and `SharedStream` hold the `reading` flag for the whole `read()`, `pace()` included: a second reader
  is a `LogicException` even when bytes have arrived for the first, which would otherwise wait forever for them.
- One `fread()` asks for at most 1 MiB (`MAX_READ`): PHP allocates the whole length before reading, so a length
  from a header would cost its size in memory before a byte has arrived. `Socket` of thesis/net does the same.
- Reads and writes that do not wait go through one `Pacer(16)` per stream: a file, /dev/null or a fast peer
  never makes them wait, and they must not hold the event loop. The pacer yields before the operation,
  so a cancelled one has done nothing.
- `Stream` reads first and waits only on an empty read, never the other way round: a regular file or php://memory
  never has to wait, and the event loop cannot watch them at all (the uv driver rejects a file). Its PHP read buffer
  is off for the same reason. The rest of its discipline (the `StreamWatcher` closed before `fclose()`,
  the write mutex taken only by writes that wait) is that of `Socket` of thesis/net:
  a fix of one is checked against the other.
- stdio is `Internal\SharedStream`, which never touches the mode of the descriptor: `O_NONBLOCK` belongs
  to the open file description, shared with the shell, the jobs of the same terminal, the children that inherit it
  and the next commands of a pipeline, and they would get `EAGAIN` while it is on (or `echo` of our own process
  would drop output). A call is made only once `stream_select()` with a zero timeout says it will not block,
  a write passes at most `PIPE_BUF` (4096) bytes, and the waits go through a `StreamWatcher`.
  Under `UvDriver` stdio is never watched: `uv_poll_init()` sets `O_NONBLOCK` and libuv never clears it,
  so a wait there is `StreamWatcher::delay()` of 10 ms and the select again. Ev and Event leave the mode alone.
  The `StdioTest` checks of the mode run the child on the driver of the tests, so CI checks every driver.
  Toggling the mode around each call was rejected: a neighbour blocked on the same full pipe wakes with us
  and sees the flag (half of its writes failed in a measurement).
- stdio streams are over duplicates (`php://stdout` dups the descriptor in the CLI), so `close()` leaves `STDOUT` open.
  A write cancelled after a part leaves stdio open: closing would leave the process without its stdout for good.

## Decisions

- io may wait through thesis/async: stdio and streams of processes need it, and putting them elsewhere
  would split the streams of the ecosystem over packages. Rejected: an io without an event loop for synchronous users
  of `Buffer` (a protobuf codec in FPM); async, time and revolt are pure PHP and cost them only files in `vendor/`.
- `Stream` is non-blocking, not a blocking adapter beside a non-blocking one: in a fiber a blocking read holds
  every other task. Sockets stay in thesis/net: TLS, addresses and half-close are theirs.
- No zlib streams and no in-memory pipe until HTTP needs them (`Content-Encoding`, a request body that the parser
  writes and the handler reads). Nobody else does: WebSocket permessage-deflate is raw deflate per message
  on `deflate_add()`, MongoDB compresses whole messages, protocol tests use `socketPair()` of thesis/net,
  values between fibers go through `Channel` of thesis/async. The roadmap keeps what a first version found.
- stdio is functions with a `static` stream, typed `Reader&Closer` and `Writer&Closer`: its class is internal.
  Rejected: constants (`const STDOUT = new Stream(...)`):
  a constant initializer cannot call `fopen()` for a duplicate, it runs when its file loads (for every process
  that loads it), and constants are not autoloaded.
- Rejected for stdio under uv: one long-lived watcher per descriptor, since `O_NONBLOCK` stays on after
  the first wait anyway, and restoring the mode after a wait, since it is on during the wait (toggling).
  Polling costs up to 10 ms of latency and a wakeup per 10 ms while a wait lasts, on uv only.
- Rejected for stdio: a descriptor non-blocking for the whole run, restored at shutdown (amphp, Node.js):
  others sharing it fail meanwhile. Reopening it for a description of our own (as libuv does for a terminal):
  `/proc/self/fd/N` reopens a pipe only on Linux, a file gets an offset of its own, a socket cannot be reopened.
- `Stream` has no chunk size: with the PHP read buffer off a read asks the descriptor for the length it is given.
- A failed call of PHP is `error_clear_last()`, `@call()`, then `Internal\lastError($default)` for the message.
  Rejected: `exceptionally()` of thesis/exceptionally. Nothing suspends between the clear and the read, so the global
  last error is this call's. `exceptionally()` would cost a closure and two handler calls per `fread()`/`fwrite()`
  of the hot path, and it loses the message where `lastError()` keeps it: under `@` or `error_reporting` of the caller
  it throws nothing, while `error_get_last()` records a suppressed error too. A default is needed anyway:
  `fread()` and `fwrite()` may fail without an error.

## Tests

- `BufferFuzzTest` compares `Buffer` with the deliberately naive `ReferenceBuffer` on random sequences of operations.
  A new `Buffer` method gets a counterpart in `ReferenceBuffer` with a different algorithm and joins the fuzz test.
