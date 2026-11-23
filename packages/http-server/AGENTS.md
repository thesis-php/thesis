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
- An integer endpoint in `Config` is a TCP port on `127.0.0.1`. Strings starting with `/` are absolute Unix paths.
  Other strings must be numeric TCP addresses with a port and brackets around IPv6. A relative path needs `UnixEndpoint`.
- Both endpoint types own their backlog. TCP owns `reusePort` and `noDelay`. TLS, limits and timeouts live on `Config`.
- Like the AMQP client, the server starts a daemon in the group passed to `run()`. It returns once the daemon
  has registered shutdown. A handler may call `close()`: the group joins active exchanges after it returns.

- The listener owns its sockets and drains active exchanges on shutdown. The internal `Connection::serve()`
  runs HTTP/1 exchanges on a reader and writer so tests and benchmarks can run without sockets.
- Each connection keeps its last parsed head if the wire head fits in 1 KiB, excluding the final delimiter.
  A byte-for-byte match can reuse the immutable head. The scheme belongs to the connection after the TLS handshake.
  Create the request and its body reader anew even when two heads match.
- The listener tracks socket operations with the same positive timeout in start order. A keep-alive read and
  a response write can share a timer when their timeouts match. Different durations need separate queues.
  Key each entry by operation: a second write can wait for the same socket while the first still owns it.
  Expiry closes the socket. An expired idle read ends silently and an expired write throws `WriteFailed`.
  A zero timeout uses the cancellation scope so an operation that completes without waiting can still succeed.
- While waiting for the next request, yield if other socket operations are pending: the peer can send its
  request while other clients run. If the first bytes match the last head including its final delimiter,
  keep only the trailing bytes in the buffer. They may include a body and a pipelined request.
- The keep-alive read can receive a whole head under the idle timeout. Keep bytes after its delimiter
  for the body or next request. Only a head that still needs bytes needs a head timer.
- Keep-alive has no request count limit by default. This lets busy clients reuse the socket without periodic
  reconnects. The idle timeout still applies between requests. An explicit request limit closes after the last response.
- Write a small string body with its response head. Reading a stream can wait for the peer to receive
  the head, so send the head before reading a streaming body. A small fixed-length echo can share
  that write when the connection already has all its remaining input. A large string is already in memory:
  send it in blocks of up to 64 KiB to amortize the socket write and its timer. Keep streaming reads at 8 KiB.
- A fixed-length body already in the buffer has passed the size check and cannot wait on the transport.
  Its fixed-length reader still tracks unread bytes. Wrap it when the first read must send `100 Continue`.
- Close after an unread body. Draining it would compete with any outstanding application read and would need
  a separate size limit and deadline.

## Where to resume HTTP/1 performance work

- For writers other than sockets, measure the cancellation scope around each response write. In `ConnectionBench` with 512 requests
  and an in-memory writer, bypassing the entire write deadline saved 19.1% of instructions. This
  measures the scope, timer and call together. The heap change in `f39fec8` saved about 1% on the
  same exchange. On PHP 8.6 a synchronous timeout took 1.36 µs, constructing, binding and closing its
  scope took 0.65 µs, and registering and cancelling a timer in isolation took 0.47 µs (warm medians).
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
- Against `27496ba`, reusing the last short head on the connection raised the median of a repeated plaintext GET
  from 49.8k to 57.1k responses/s with 64 clients, and lowered median p99 from 2.39 to 2.01 ms.
  Alternating two paths missed on every request (46.5k vs 47.7k). A single client sending 16 identical requests
  per batch went from 64.0k to 73.7k responses/s; median batch p99 was 438 vs 386 µs.
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

### HTTP Arena H1 Baseline

- Run `examples/baseline11.php` with one PHP process, CLI OPcache on and Xdebug off. Use the same
  handler and listener settings for both revisions. The active connection limit and the TCP listen
  backlog are separate. Set both to 4096 for this profile: the default backlog of 511 overflowed
  when the clients connected together. One run then lost 33 replies across reconnects.
- At `b308384` the Baseline section of HTTP Arena's `scripts/validate.sh` at `3eb74a7` passed its
  13 checks. Its `validate-frag.py` passed all 1047 splits across nine request shapes.
- Use Arena's `requests/get.raw,post_cl.raw,post_chunked.raw` as the three paths in gcannon's
  `--raw` list, in that order. On Docker Desktop Linux arm64 (8 CPUs shared by server and client),
  gcannon 0.8.4 ran on the server's loopback with `-c 4096 -t 4 -p 1 -s 200 --settle 5s -d 20s --json`.
  Across five runs at `b308384`, median throughput was 29,426 replies/s (27,512–32,557).
  Median p99 was 173.6 ms (145.4–231.2). All 2,965,257 requests got a 2xx reply, with no reconnects
  or errors. Compare candidates with interleaved base runs on the same machine.
- Sharing the idle timer across sockets raised throughput from 30,386 to 38,429 replies/s (26.5%)
  and lowered median p99 from 182.2 to 120.9 ms, across four interleaved runs of each version.
  All three request templates returned 2xx with no errors or reconnects.
- Rejected: keeping four parsed heads per connection. In the first 700,000 Baseline requests the single-entry
  cache missed exactly 4096 times, once per connection. The load generator uses different templates across
  connections but keeps sending the same one on each connection in this run.
- Against `0a5923c`, the socket write deadlines and buffered head and body changes together raised the median
  from 36,438 to 41,750 replies/s (+14.6%) and lowered median p99 from 166.0 to 122.3 ms.
  Four interleaved runs per version used the same handler and settings. All 3,340,168 candidate replies
  were 2xx, with no transport errors or reconnects.
