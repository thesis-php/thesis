# thesis/http-server

## Invariants that must not be broken

- One buffer belongs to the connection. Finish writing the response before checking whether the incoming body
  ended. An echo response can consume the request reader after the handler returns.
- Reuse requires zero remaining bytes for fixed-length input or ready trailers for chunked input.
- A final response head commits the response. If writing the body or its trailers fails, close the connection.
  A second status line would become part of the first response.
- A timeout on the whole request head includes every fragment. The body idle timeout starts anew on each
  transport read, including reads of chunk framing. A keep-alive wait expires silently before the next request.
- Each accepted socket occupies one slot until it closes. Shutdown must wake the supervisor even when
  the accept loop is waiting for a slot. That is why it waits on `Net\Server::awaitClose()`.

## Decisions

- The constructor takes the configuration and handler. Bind the endpoint in `run()` so a DI container can build the server
  without opening sockets and the application sees a bind failure at startup.
- Like the AMQP client, the server starts a daemon in the group passed to `run()`. It returns once the daemon
  has registered shutdown. A handler may call `close()`: the group joins active exchanges after it returns.

- The listener owns its sockets and drains active exchanges on shutdown. The internal `Connection::serve()`
  runs HTTP/1 exchanges on a reader and writer so tests and benchmarks can run without sockets.
- The keep-alive read can receive a whole head under the idle timeout. Keep bytes after its delimiter
  for the body or next request. Only a head that still needs bytes needs a head timer.
- Write a small string body with its response head. Reading a stream can wait for the peer to receive
  the head, so send the head before reading a streaming body. A small fixed-length echo can share
  that write when the connection already has all its remaining input. A large string is already in memory:
  send it in blocks of up to 64 KiB to amortize the socket write and its timer. Keep streaming reads at 8 KiB.
- Close after an unread body. Draining it would compete with any outstanding application read and would need
  a separate size limit and deadline.

## Where to resume HTTP/1 performance work

- Profile the cancellation scope around each response write. In `ConnectionBench` with 512 requests
  and an in-memory writer, bypassing the entire write deadline saved 19.1% of instructions. This
  measures the scope, timer and call together. The heap change in `f39fec8` saved about 1% on the
  same exchange. Measure scope entry and exit separately before changing the timer queue again.
- Rejected: starting the write timeout only when the socket watcher waits. A write can already have
  suspended behind another writer or in the pacer. Check a client that stops reading a large response:
  the write must time out while independent clients still receive replies.
- Rejected: holding one pipelined response until the next handler finishes. That handler may wait for
  an action the client takes only after receiving the first response. A failed first write must also
  stop the connection before the second handler runs.
- Rejected: making the buffer return the delimiter position from the fill so the head reader can
  reuse it. A complete buffered head already takes one search. The prototype sped up a 24 KB head
  in memory (22.11 to 20.45 µs), but slowed a head arriving 32 bytes at a time and a fragmented
  TCP request (904 to 836 requests/s). Reconsider if production profiles show large heads dominate.
- For codec experiments see the [HTTP package decisions](../http/AGENTS.md#http1-codec-performance).
  For timer tradeoffs see the [async package decisions](../async/AGENTS.md).

### Comparing with earlier measurements

- Use `make bench P=http-server` for instruction counts. For TCP, use PHP 8.6 with CLI OPcache
  and Xdebug off. Run base, candidate, candidate, base on the same host, discard warm-up and check
  every response. Match TCP_NODELAY, framing and response headers across servers. A split write
  without TCP_NODELAY introduced about 40 ms of delay in the fragmented-head experiment.
- Earlier TCP comparisons explain the current block sizes. At `4992a57`, 64 KiB string writes
  raised throughput by 71% for a 64 KiB response and 59% for 256 KiB. At `016ed2c`, writing a
  buffered echo with its head gained 24% for 256 bytes and 16% for 4 KiB. When changing either
  path, check a partially received echo whose client waits for the response head before sending the rest.
- From `016ed2c` to `f39fec8`, 64 keep-alive clients with one GET outstanding each went from
  52.3k to 54.5k responses/s on Docker loopback. One client sending 16 requests at once went from
  58.8k to 56.9k; batch p99 went from 421 to 427 µs. A separate run reversed the pipeline result.
  Report throughput for both workloads and label p99 as the time until the last response of a batch.
- The earlier one-process comparison with amphp/http-server 3.4.9 measured 50.8k vs 18.3k
  responses/s on plaintext and 37.4k vs 9.45k on a 4 KiB streaming echo. Both used TCP_NODELAY,
  Date and Content-Length. For a cross-server ranking, rerun the peers together. Workerman and
  Node's HTTP server are open comparisons; the local Amp result covers neither.
