# thesis/io

`Buffer` supports typed big-endian and little-endian encoding and varints. The package defines `Reader`,
`SizedReader`, `Writer` and `Closer` interfaces. `StringReader` reads a string in memory. `Stream` wraps a
non-blocking PHP resource, while `stdin()`, `stdout()` and `stderr()` expose the standard streams.
Streams wait through thesis/async.
Read `README.md` for the API and design decisions.

## Invariants that must not be broken

- `Buffer` is the hot path of every binary protocol: a change to it is justified by a benchmark, not by taste.
  Its micro-optimizations (properties with defaults instead of `readonly`, bounds checks folded into the byte fetch,
  `chr()` instead of `pack()` for 1-2 bytes, `clone` in `slice()`) are measured: keep them and their comments.
  `slice()` is `clone $this` and assignments, not `clone($this, [...])`: the array of `clone()` makes it 33-43% slower.
- Every read is O(1): the cursor advances, consumed bytes are discarded only in `fill()`.
- A failed read throws `UnexpectedEof` and leaves the cursor where it was.
- Range checks of written values are `assert()` only, never a runtime `if`: they cost nothing in production.
- `readUint64()` throws `\OverflowException` above `PHP_INT_MAX`, varints keep the raw 64-bit pattern.
- Marks of `reserve()` are absolute positions, invalidated by `fill()`, `flush()` and `drain()`.
- `Reader::read()` returns 1 to `$max` bytes, throws `UnexpectedEof` at the end, `ReadFailed` on any other failure
  (a reset is not an end: a protocol may treat an EOF between messages as a clean close). If `close()` is called
  on the reader before or during a read, the read throws the reader's own `IoException` (`StreamClosed`,
  `SocketClosed`). `Writer::write()` writes all bytes, `Closer::close()` is idempotent.
- `SizedReader::$size` is the total size in bytes, including bytes already read. `StringReader` advances an
  offset into the original string and reads without suspending the fiber.
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
  never has to wait, and the event loop cannot watch them at all (`Io\Poll` rejects a file). Its PHP read buffer
  is off for the same reason. Both `Stream` and thesis/net's `Socket` close the `StreamWatcher` before
  `fclose()`. Only writes that wait take the write mutex. When you fix either class, check the other class too.
- stdio is `Internal\SharedStream`, which never touches the mode of the descriptor: `O_NONBLOCK` belongs
  to the open file description, shared with the shell, the jobs of the same terminal, the children that inherit it
  and the next commands of a pipeline, and they would get `EAGAIN` while it is on (or `echo` of our own process
  would drop output). A call is made only once `stream_select()` with a zero timeout says it will not block,
  a write passes at most `PIPE_BUF` (4096) bytes, and the waits go through a `StreamWatcher`
  (`Io\Poll` leaves the mode alone, unlike libuv, which sets `O_NONBLOCK` for good).
  Toggling the mode around each call was rejected: a neighbour blocked on the same full pipe wakes with us
  and sees the flag (half of its writes failed in a measurement).
- stdio streams are over duplicates (`php://stdout` dups the descriptor in the CLI), so `close()` leaves `STDOUT` open.
  A write cancelled after a part leaves stdio open: closing would leave the process without its stdout for good.

## Decisions

- io may wait through thesis/async: stdio and streams of processes need it, and putting them elsewhere
  would split the streams of the ecosystem over packages. Rejected: an io without an event loop for synchronous users
  of `Buffer` (a protobuf codec in FPM); async and time are pure PHP and cost them only files in `vendor/`.
- `Stream` is non-blocking, not a blocking adapter beside a non-blocking one: in a fiber a blocking read holds
  every other task. Sockets stay in thesis/net: TLS, addresses and half-close are theirs.
- No zlib streams and no in-memory pipe until HTTP needs them (`Content-Encoding`, a request body that the parser
  writes and the handler reads). Nobody else does: WebSocket permessage-deflate is raw deflate per message
  on `deflate_add()`, MongoDB compresses whole messages, protocol tests use `socketPair()` of thesis/net,
  values between fibers go through `Channel` of thesis/async.
- stdio is functions with a `static` stream, typed `Reader&Closer` and `Writer&Closer`: its class is internal.
  Rejected: constants (`const STDOUT = new Stream(...)`):
  a constant initializer cannot call `fopen()` for a duplicate, it runs when its file loads (for every process
  that loads it), and constants are not autoloaded.
- Rejected for stdio: a descriptor non-blocking for the whole run, restored at shutdown (amphp, Node.js):
  others sharing it fail meanwhile. Reopening it for a description of our own (as libuv does for a terminal):
  `/proc/self/fd/N` reopens a pipe only on Linux, a file gets an offset of its own, a socket cannot be reopened.
- `Stream` has no chunk size: with the PHP read buffer off a read asks the descriptor for the length it is given.

## Tests

- `BufferFuzzTest` compares `Buffer` with the deliberately naive `ReferenceBuffer` on random sequences of operations.
  A new `Buffer` method gets a counterpart in `ReferenceBuffer` with a different algorithm and joins the fuzz test.
