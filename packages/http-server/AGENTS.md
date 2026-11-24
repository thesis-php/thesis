# thesis/http-server

## Invariants that must not be broken

- One buffer belongs to the connection. Finish writing the response before checking whether the incoming body
  ended. An echo response can consume the request reader after the handler returns.
- The writer takes the response body once, uses it for framing and writing, and closes it even on failure.
  Keep the incoming decoder to check for a direct echo and to decide whether the connection can be reused.
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
- Rejected: opening a group for every request. Even with lazy task and daemon scopes from `499def1`,
  an unused group raised the median time per in-memory plaintext GET from 3.67 to 5.01 µs.
  A handler can open its own group for parallel work that finishes before it returns. A streaming body
  can produce bytes as the server reads it. Few tasks need to keep running independently after the handler
  returns. Revisit this if middleware needs the server to own such a task until the response has been sent.

- The listener owns its sockets and drains active exchanges on shutdown. The internal `Connection::serve()`
  runs HTTP/1 exchanges on a reader and writer so tests and benchmarks can run without sockets.
- Each connection keeps its last parsed head if the wire head fits in 1 KiB, excluding the final delimiter.
  A byte-for-byte match can reuse the immutable head. The scheme belongs to the connection after the TLS handshake.
  Create the request and its body reader anew even when two heads match.
- The listener tracks socket operations with the same positive timeout in start order. A keep-alive read and
  a response write can share a timer when their timeouts match. Different durations need separate queues.
  Key each entry by operation: a second write can wait for the same socket while the first still owns it.
  Expiry closes the socket. An expired idle read ends silently and an expired write throws `WriteFailed`.
  Timestamp a write before its immediate attempt. Queue only a write with bytes left, and close the socket
  if cancellation interrupts the rest after a partial write.
  A zero timeout uses the cancellation scope so an operation that completes without waiting can still succeed.
- While waiting for the next request, yield if other socket operations are pending: the peer can send its
  request while other clients run. If the first bytes match the last head including its final delimiter,
  keep only the trailing bytes in the buffer. They may include a body and a pipelined request.
  After a read below 2 KiB, ask for 2 KiB next time. A full 2 KiB read restores the usual 8 KiB size.
- The keep-alive read can receive a whole head under the idle timeout. Keep bytes after its delimiter
  for the body or next request. Only a head that still needs bytes needs a head timer.
- Keep-alive has no request count limit by default. This lets busy clients reuse the socket without periodic
  reconnects. The idle timeout still applies between requests. An explicit request limit closes after the last response.
- Write a small string body with its response head. Reading a stream can wait for the peer to receive
  the head, so send the head before reading a streaming body. A small fixed-length echo can share
  that write when the connection already has all its remaining input. A large string is already in memory:
  send it in blocks of up to 64 KiB to amortize the socket write and its timer. Keep streaming reads at 8 KiB.
- Reuse the last encoded length-framed response head up to 1 KiB for a string body when its length,
  status, version, persistence and ordered fields match. HEAD and CONNECT have their own framing rules.
  A string body with trailer fields uses the head formatter. The formatter reads a custom body's trailer state once.
- A fixed-length body already in the buffer has passed the size check and cannot wait on the transport.
  Its fixed-length reader still tracks unread bytes. Wrap it when the first read must send `100 Continue`.
- A partially buffered fixed-length body still needs failure tracking and may need to send 100 Continue.
  Its declared length has passed the size limit, so the server passes a running decoded-byte budget to
  `BodyReader` only for chunked input.
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
- For codec experiments see the [HTTP protocol package decisions](../http-protocol/AGENTS.md#http1-codec-performance).
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
- Against `f477ba2`, immediate plaintext writes, smaller keep-alive reads after short requests, and reuse
  of checked fields and encoded response heads raised median throughput from 42,359 to 48,389 replies/s
  (+14.2%). Median p99 fell from 122.4 to 103.8 ms. Across four interleaved runs per version, all
  3,860,555 candidate requests got a 2xx reply with no reconnects or errors. The Baseline validator
  passed all 13 checks and all 1047 fragmentation offsets across nine request shapes.
- On an M1 Pro with eight Docker Desktop arm64 vCPUs, reserve CPUs 4–7 for gcannon. Give the first
  1, 2 or 4 vCPUs to either one Thesis process per vCPU with SO_REUSEPORT or the same number of
  true-async worker threads. With the post-f477ba2 changes, three runs per configuration gave median
  throughput of 45k/90k/199k for Thesis and 72k/131k/344k for true-async. Both Arena handlers passed
  validation. They do different application work on the same inputs, and macOS controls physical core placement.
- At `ab4b259` on the one-vCPU M1 Pro setup above, a constant two-byte GET response through each
  server's API gave 64.2k/83.4k replies/s (Thesis/true-async). Identical query computation gave
  60.8k/77.3k. The stock handlers gave 59.8k/77.1k on GET, 49.3k/76.3k on Content-Length POST
  and 44.9k/75.1k on chunked POST. Use interleaved runs: Thesis chunked ranged from 30.8k to 45.2k.
- Rejected: replacing the keep-alive checkpoint and immediate read with a direct socket read under
  the same idle deadline. A checkpoint with 4096 runnable fibers cost 1.60 µs in isolation, but
  the mixed Arena median fell from 48.9k to 44.0k replies/s across three pairs. Both versions passed
  the Baseline and fragmentation checks.
- On the same one-vCPU mixed workload at `ab4b259`, throwing a preallocated `UnexpectedEof` on
  normal completion of fixed-length and chunked HTTP bodies raised throughput in all three pairs
  (median 49.5k to 58.3k replies/s, p99 101.8 to 87.3 ms). The handler still drained each body.
  Keep this as a diagnostic: callers of a shared exception see its allocation site and the same
  exception object across requests. Disabling exception trace arguments alone gave mixed results.
- Returning null at normal EOF in the request body and reading until null in the handler raised the
  mixed Arena median from 43.8k to 55.0k replies/s in three pairs. The stock runs varied from 28.3k
  to 44.6k, the candidate from 51.6k to 58.6k. Both passed the validator. This measured the request
  body path only, with the old transport and Reader contract.
- With nullable reads throughout the packages, the mixed Arena median rose from 33.9k replies/s
  at `ab4b259` to 46.4k, and median p99 fell from 272.8 to 159.4 ms. Five of six pairs gained
  throughput. Both arms had about 22% relative standard deviation on the working Mac. All replies
  were 2xx with no reconnects or errors, and both arms passed the Arena validators.
- At `21ff928`, three one-vCPU M1 Pro pairs gave Thesis/true-async medians of 58.1k/78.5k replies/s on the mix,
  58.8k/79.5k on GET, 58.9k/78.3k on Content-Length POST and 55.4k/76.1k on chunked POST. The same constant
  two-byte GET response through both APIs still gave 65.6k/87.2k. Include a GET control when measuring POST costs.
- Rejected: passing a known response length of at most 8 KiB directly to `Body::read()` in `Connection::readBody()`
  to skip the buffering check and block-size clamp. In eight alternating in-process rounds, writing a two-byte
  response took 4.3% less time at the median, but a 64 KiB response took 5.0% more. Across three TCP pairs at
  `21ff928`, two lost throughput on GET and two on the Arena mix. The throughput ranges overlapped on both.
- At `7eaf938`, Thesis served 72.9%, 80.7% and 75.2% of true-async throughput in three mixed pairs
  with one server vCPU. Median p99 was 110.9 ms for Thesis and 92.7 ms for true-async.
- Rejected: checking for an exact whole-head match before the cached prefix match to skip writing an empty suffix.
  Against `7eaf938`, all three mixed pairs lost throughput (1.0%, 12.5%, 9.5%) and p99 rose in all three.
- Rejected: trying a keep-alive read before the idle deadline and checkpoint when another socket is waiting,
  then using the usual checkpoint on a miss. Mixed throughput fell in all three pairs (4.9%, 4.5%, 1.7%).
- Rejected: four PHP processes sharing one server vCPU with `SO_REUSEPORT` for the 4096-client mix.
  The worker CPU masks all contained only CPU 0. Two pairs lost 27.7% and 52.7% throughput and one tied
  within 5 replies/s. Candidate throughput varied from 22.7k to 57.2k replies/s on the shared machine.
- At `7eaf938`, a fixed GET through the ordinary server returned the same 66 bytes as a loop that recognized
  only the benchmark request and wrote a prebuilt reply. Both used the same listener, connection fibers,
  socket deadlines and write pacing. With 4096 connections on one server vCPU, the simpler loop was faster
  in six of six pairs: median 66.7k to 90.7k replies/s, p99 94.4 to 77.2 ms. This comparison removes
  request processing, handler dispatch, message construction and response formatting together.
- Rejected: detaching a fully buffered fixed-length request body of up to 8 KiB into a string and bypassing
  the streaming response loop for a small, unread string response. Both paths use one socket write.
  The first three mixed pairs gained 13.8%, 4.6% and 3.6% RPS.
  The repeat changed RPS by −19.3%, −10.7% and +3.2%. Across six pairs the base and candidate medians were
  both 59.0–59.1k replies/s, with p99 of 97.7 and 97.3 ms. See the fixed GET comparison above for the cost
  of the entire HTTP path.
  A response body wrapping the input body can forward its `isBuffered` property. Marking the input buffered
  made that response close the connection after the first of two pipelined POSTs. The base served both.
- Rejected: releasing the connection fiber between requests and spawning a new task when the plaintext socket
  becomes readable. Against `d3043d3`, mixed Arena throughput fell 20–23% in all three pairs. The readiness
  callback also spawned the task outside the listener's scoped values.
- Rejected: processing up to 128 ready connections per worker with a second worker available for a waiting handler.
  Restoring the listener's scoped values fixed the repeated-request probe, but mixed p99 rose in all three pairs
  (median 94.4 to 205.4 ms). Two pairs lost throughput.
- Rejected for throughput: sharing identical short parsed request heads across live connections through weak references.
  Against `d3043d3`, 4096 sockets sending identical GETs used 3.40 MiB less PHP heap. Across nine mixed pairs the
  medians were 56,219 and 56,209 replies/s. Replacing each head with a unique GET cost 7.8% more time per head
  at the paired median. Keep the memory result specific to workloads with many identical requests.
- At `d3043d3` on the one-vCPU Arena mix, PHP 8.6 tracing JIT raised throughput in five of six pairs.
  Use CLI OPcache with `-d opcache.jit=tracing -d opcache.jit_buffer_size=64M` to reproduce this mode.
  Against JIT disabled, median throughput rose from 54,850 to 58,086 replies/s and p99 fell from 106.0 to 103.1 ms.
  Check JIT status in each server process and use the same runtime flags on both arms of a server comparison.
  With tracing JIT enabled for both servers, three fresh pairs gave Thesis 81.8%, 77.7% and 81.6% of true-async RPS.
- Rejected: dropping the next keep-alive read from 2048 to 256 bytes after two reads below 256 bytes.
  The first six JIT-enabled mix pairs showed a small gain, but a third block changed RPS by −3.8%, −2.6% and −0.03%.
  A 1 KiB head after consecutive short heads required two reads instead of one.
- Rejected: returning hot connections to the end of a shared worker queue and checking up to 128 before yielding.
  Against `d3043d3` with tracing JIT, all six mixed pairs gained RPS and reduced p99 (58.7k to 66.8k replies/s,
  108.4 to 81.2 ms at the medians). A connection changed fibers across requests and lost an earlier `onCancel`
  callback at shutdown. Keeping each connection's task and resuming batches of subscribed continuations gained
  only 0.47% in total mixed replies over six pairs, while GET p99 rose from 94.5 to 159.3 ms at the medians.
  The stock server and both candidates installed no further read watchers after the first 10k hot GET exchanges.
- Rejected: parsing and writing in a shared worker while running the handler and its response body in a
  dedicated nested fiber. It preserved connection-scoped cancellation and passed the streaming-body tests.
  Across nine JIT-enabled mixed pairs it handled 1.69% more replies in total. The last pair lost 12.85% RPS
  and raised p99 from 109.4 to 179.7 ms. With 4096 warmed connections sitting idle, RSS was 183 vs 413 MiB.
- Rejected for throughput: storing deadline timestamps and sockets in separate arrays keyed by operation.
  With 4096 outstanding entries it saved 749,728 bytes of PHP heap in a queue control. Mixed Arena throughput
  fell 0.5%, 4.8% and 1.2% across three pairs and median p99 rose from 124.8 to 159.0 ms.
- In a second tracing-JIT comparison of the unchanged stock handler, the three paired percentages of
  true-async throughput were 87.9%, 94.5% and 81.3%. Median RPS was 57,080 vs 67,325 and median p99
  was 145.5 vs 141.1 ms. Both processes ran on the same server vCPU in alternating runs.
