# thesis/io

Fast binary I/O: `Buffer` with typed big/little-endian encoding and varints, the `Reader`, `Writer`, `Closer`
interfaces, and the streams: `Stream` over a non-blocking PHP resource with `stdin()`/`stdout()`/`stderr()`.
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
- `Reader::read()` returns 1 to `$max` bytes, throws `UnexpectedEof` at the end and `ReadFailed` on any other failure
  (a reset is not an end: a protocol may treat an EOF between messages as a clean close), `Writer::write()` writes all bytes,
  `Closer::close()` is idempotent.
- A read that throws, `Cancelled` included, has taken nothing: `Buffer::fill()` loses no bytes only because of it.
  A decorator keeps it too: it changes its state only after the inner read has returned.
  A write that throws after writing a part leaves its writer unusable (`Stream` closes itself):
  a retry would duplicate bytes or leave a hole.
- `Buffer`, `Order` and the interfaces do not use thesis/async: only streams that wait do.
- `Stream` reads first and waits only on an empty read, never the other way round: a regular file or php://memory
  never has to wait, and the event loop cannot watch them at all (the uv driver rejects a file). Its PHP read buffer
  is off for the same reason. The rest of its discipline (the `StreamWatcher` closed before `fclose()`,
  the write mutex taken only by writes that wait, a second reader is a `LogicException`, `Pacer(16)` on reads)
  is that of `Socket` of thesis/net: a fix of one is checked against the other.
- stdio streams are `Stream::shared()`: blocking between calls, non-blocking only inside `fread()`/`fwrite()`.
  `O_NONBLOCK` belongs to the open file description, shared with the shell, the jobs of the same terminal,
  the children that inherit it and the next commands of a pipeline: left on, it gives them `EAGAIN`,
  and `echo` of our own process would drop output. Readiness is waited for in the blocking mode: the event loop
  only polls. Only a fatal error or a kill inside one of those calls leaves the mode on (`StdioTest` checks
  others while we run and the next process after `write()` and after `close()`).
- stdio streams are over duplicates (`php://stdout` dups the descriptor in the CLI), so `close()` leaves `STDOUT` open.

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
- stdio is functions with a `static` stream. Rejected: constants (`const STDOUT = new Stream(...)`):
  a constant initializer cannot call `fopen()` for a duplicate, it runs when its file loads (for every process
  that loads it), and constants are not autoloaded.
- Rejected for stdio: a descriptor non-blocking for the whole run, restored at shutdown (amphp, Node.js):
  others sharing it fail meanwhile. Reopening it for a description of our own (as libuv does for a terminal):
  `/proc/self/fd/N` reopens a pipe only on Linux, a file gets an offset of its own, a socket cannot be reopened.
- Windows is not supported by stdio: PHP cannot make a pipe non-blocking there.

## Tests

- `BufferFuzzTest` compares `Buffer` with the deliberately naive `ReferenceBuffer` on random sequences of operations.
  A new `Buffer` method gets a counterpart in `ReferenceBuffer` with a different algorithm and joins the fuzz test.
