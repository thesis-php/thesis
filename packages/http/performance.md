# HTTP/1 performance

## Where to resume HTTP/1 performance work

- For writers other than sockets, measure the cancellation scope around each response write. In `ServerConnectionBench` with 512 requests
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
- For codec experiments see the [HTTP/1 codec decisions](#http1-codec-performance).
  For timer tradeoffs see the [async package decisions](../async/AGENTS.md).

### Peer disconnect cancellation

The connection registers a callback on the event loop after the handshake. On Linux epoll,
a plaintext socket waits for read hangup. Idle handlers share the loop and need no second fiber
or periodic disconnect checks.

With 1000 one-hour handlers, median RSS growth from an empty server was 32,896 KiB compared
with 30,828 KiB before disconnect monitoring (`d89b464`). The additional 2068 KiB is about
2.02 MiB total or 2.07 KiB per waiting connection. Each median covers three fresh PHP processes.
The current server with the callback disabled grew by 30,824 KiB. PHP heap growth was 27,523 KiB
with callbacks and 25,727 KiB with callbacks disabled.

Across five cycles of opening and closing 1000 clients, the handler count returned to zero each
time and the PHP heap after cleanup stayed at 4,410,272 bytes. With 1000 idle handlers over
30 seconds, the original and callback servers used 0.04 and 0.05 CPU seconds respectively.

Each server ran the baseline11 GET on the same Linux CPU with PHP 8.6, CLI OPcache on,
Xdebug and access logging off. The load generator used another CPU, validated each response
and checked the waiting handler count after every sample. The rates below are medians of six
interleaved four-second samples per version after warm-up.

| Waiting handlers | Short GET workload | Original (responses/s) | Callback (responses/s) | Change |
| ---: | --- | ---: | ---: | ---: |
| 0 | 64 keep-alive clients | 86,152 | 87,780 | +1.89% |
| 0 | One client, batches of 16 | 74,689 | 73,980 | −0.95% |
| 1000 | 64 keep-alive clients | 83,399 | 81,754 | −1.97% |
| 1000 | One client, batches of 16 | 78,781 | 73,745 | −6.39% |

The pipeline run with 1000 waiting handlers crossed the 5% budget in four-second samples.
Repeating that workload in three ABBA blocks of 12 seconds per sample gave median rates of
76,754 original and 80,638 callback responses/s (+5.06%).

With 1000 waiting handlers, 30 more clients disconnected one at a time. From socket close to
confirmation through a separate HTTP request took 0.13 ms at the median and 5.18 ms at the
maximum on Docker loopback.

#### Earlier monitor with a companion fiber

The earlier implementation watched each socket from a second fiber. On Linux epoll it registered read hangup
once per plaintext connection. With 1000 handlers in a one-hour delay, short GET throughput
was within the 5% budget compared with `d89b464` before disconnect monitoring.

| Waiting handlers | Short GET workload | Original (responses/s) | Event-driven (responses/s) | Change |
| ---: | --- | ---: | ---: | ---: |
| 0 | 64 keep-alive clients | 107,725 | 108,386 | +0.61% |
| 0 | One client, batches of 16 | 95,214 | 96,369 | +1.21% |
| 1000 | 64 keep-alive clients | 109,108 | 108,671 | −0.40% |
| 1000 | One client, batches of 16 | 95,703 | 99,084 | +3.53% |

Each server ran the baseline11 GET on the same Linux CPU with PHP 8.6, CLI OPcache on, Xdebug and
access logging off, and 4096 connection slots. Restore the original async, net and HTTP classes
for the baseline. After warm-up, take six interleaved four-second samples per version and workload.
The load generator on a second CPU checked every response and verified the waiting count after each sample.
With 1000 other handlers waiting, 30 extra clients disconnected one at a time. From closing the client
to confirming the handler had unwound through a separate HTTP request took 0.14 ms at the median
and 0.35 ms at the maximum on this loopback setup.

With 1000 waiting handlers and no short GETs, 60 seconds of wall time cost 0.11 s of process CPU
on the original server and 0.12 s with socket events (0.18% and 0.20% of one core).
Linux reported 69,128 KiB and 95,284 KiB of resident memory respectively, about 25.5 MiB extra
for the event-driven server in this workload. Closing the process holding all 1000 clients and
confirming zero waiting handlers through HTTP took 87 ms.

To isolate that RSS cost, measure the increase from zero to 1000 waiting handlers in a fresh process.
Across three runs per variant the median growth was 30,828 KiB with the disconnect monitor removed,
51,220 KiB with one extra started and suspended plain PHP fiber per connection, 55,268 KiB with a
Thesis daemon awaiting a Deferred, and 57,316 KiB with the full disconnect monitor. The plain fibers
accounted for 19.9 MiB of the 25.9 MiB difference. The last 2.0 MiB includes the live socket wait
and its bookkeeping. These are process RSS deltas, not individual allocation sizes.

#### Earlier disconnect experiments

Against `d89b464`, opening a group and scheduling a disconnect daemon for each socket exchange
reduced short GET throughput from 77.1k to 40.4k responses/s with 64 keep-alive clients.
One client sending batches of 16 requests went from 74.6k to 32.0k responses/s.
Both versions ran the baseline11 GET on Docker loopback with CLI OPcache enabled, Xdebug off and
no access log. Four runs in ABBA order checked every response. Each workload used three one-second
samples after a one-second warm-up per run. The in-memory connection benchmark skips the socket watcher.

A shared 100 ms listener check brought median throughput to 76.6k vs 76.0k responses/s
on `d89b464` with 64 clients, and 59.2k vs 59.7k with batches of 16. Both servers stayed warm
on the same Linux CPU. The load generator ran on another CPU and checked every response.
Each workload alternated eight three-second samples per version after warm-up. Both median throughputs
were within the 5% regression budget. Individual samples varied with Docker host load.

With 1000 handlers in a one-hour `delay()`, a 1 s check interval met the 5% median throughput
budget on both short GET workloads. A 100 ms interval exceeded it.

| Interval | CPU with waiting clients only (% of one core) | 64 keep-alive clients (GET/s) | Batches of 16 (GET/s) |
| --- | ---: | ---: | ---: |
| Original server without checks (`d89b464`) | 0.07% | 61,480 | 69,159 |
| 100 ms | 2.27% | 53,121 (−13.6%) | 62,494 (−9.6%) |
| 1 s | 0.40% | 59,107 (−3.9%) | 66,120 (−4.4%) |

Each server reported 1000 active waiting handlers before measurement and after each load sample.
The connection limit was 4096 so the waiting clients left room for 64 load clients.
Server CPU includes user and kernel time from Linux process counters over 15 s at 100 ticks/s.
For throughput, all three servers used the same CPU and only the server being measured ran.
The load generator used another CPU and checked each response. For each workload and version,
discard the first warm-up and take the median of six interleaved three-second samples.
Docker host load varied across samples, so the percentages describe these medians.

### Server events

Resolving subscriptions with `Dispatcher::for()` when the listener is created leaves nullable dispatchers
on the connection. With no request listeners, Tally counted 28,790,817 instructions for 512 in-memory GETs,
2.1% above the pre-event baseline and 1.5% below the per-request lookup below. This benchmark starts at
Connection and excludes listener setup, TCP acceptance and connection closure events.

Checking `listens()` in Exchange before creating each request event and timing only requests with a
RequestEnded listener raised median time from 5.63 to 5.92 µs per GET with an empty dispatcher
(+5.1% time, 4.8% fewer requests/s) against `f055ebf`. Both versions ran again under the same
ServerConnectionBench protocol described below. Tally counted 28,207,913 vs 29,239,804 instructions
for 512 requests (+3.7%).

Against the server before events (`f055ebf`), always dispatching RequestStarted and RequestEnded to an empty
dispatcher raised the median time per GET from 5.71 to 6.86 µs (+20.2%, or 16.8% fewer requests/s).
ServerConnectionBench processed 512 keep-alive plaintext requests in memory with no router or TCP.
Each revision ran 12 times in six ABBA blocks on PHP 8.6 with CLI OPcache on and JIT and Xdebug off.
Each timed run checked 51,200 responses after warm-up. Tally counted 28,207,913 vs 33,891,932 instructions
for 512 requests (+20.2%). The measurement includes constructing both events and measuring request duration.

Tally on PHP 8.6 aarch64 counted 26,700,443 instructions for 512 plaintext requests on one in-memory connection
without a server context, up from 26,341,755 (+1.36%). Peak memory was 93,320 bytes in both runs.
For 2048 parameterized route matches, instructions rose from 37,275,569 to 38,689,154 (+3.79%) and peak memory rose
from 3528 to 3984 bytes. These counts compare the event implementation with the previous callback implementation.
Passing response status and
completion flags by reference through every exchange instead cost 1.21% more instructions than the callback version.

For 512 in-memory keep-alive GETs to a parameterized route, dispatching RouteMatched unconditionally took
3.42% more instructions with an empty dispatcher (32.55M vs 31.47M) and 0.87% fewer with one listener counting
matches (32.91M vs 33.19M). In 12 alternating timing blocks with CLI OPcache on, the median paired time change
was +5.0% and -1.4% respectively. Timing varied across blocks. Keep the router's listens() check for the empty case.

### Comparing with earlier measurements

- Use `make bench P=http` for instruction counts. For TCP, use PHP 8.6 with CLI OPcache
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

- Rejected: the raw string writer at `b2e389c` avoided allocating a reader for short
  length-framed responses. With the routed handler on the Xeon,
  the three one-core pairs with a 5 s settle gained 8.72%, 7.64% and 6.84% over stock.
  With a 30 s settle, all six pairs gained throughput and lowered p99. The paired median
  gain was 7.36% on one core and 5.70% on two. Relative to TrueAsync in the same triplets,
  stock and candidate reached 72.02% and 75.80% on one core, 66.15% and 69.92% on two.
  All 23,150,861 replies were 2xx. In all 54 samples the listen queues were empty and
  the workers had accepted all 4096 connections.
- Rejected: sharing one `Headers` object across responses in the baseline example. The small
  gain does not justify moving the content type out of the response constructor and capturing
  it in the handler. With the raw string writer in both arms and 30 s to settle, this gained 1.80%, 1.04% and 1.45% on two cores. The median went
  from 77,632 to 78,919 replies/s. With the original writer and a 5 s settle, the one-core
  gains were 4.96%, 0.11% and 1.53%.

- Check the load generator's open-file limit before comparing servers. On Linux with a soft
  limit of 1024, gcannon 0.10.0 reported the requested 4096 connections and zero errors while
  only about 1020 connections were open. With `ulimit -n 65536`, `ss -Htn state established
  sport = :8080` showed all 4096 connections during each measured run.
- At `7977f38` on a Xeon E3-1230 v6, register GET and POST on the router and run one PHP 8.6
  tracing-JIT process per physical core with `SO_REUSEPORT`. Pin gcannon to two separate physical
  cores. Against HttpArena's true-async-server at `3388e458` with one worker thread per server
  core, the median paired throughput ratio was 74.7% on one core (70.9–74.9%) and 67.7% on two
  (63.0–69.2%). Three pairs ran the three raw templates with 4096 connections, pipeline depth 1,
  a 5 s settle and a 20 s measurement. Median throughput in replies/s was 37,733 vs 50,772
  on one core and 74,051 vs 107,467 on two. All 16,373,023 requests got a 2xx reply.
- Using `$request->query->get()` for both operands and leaving method selection to the router,
  the same Xeon setup gave 72.7% of TrueAsync on one core (70.2–73.9%) and 62.3% on two
  (61.8–63.0%). Median replies/s were 36,265 vs 49,684 and 73,070 vs 116,870 respectively.
  The two-core TrueAsync median rose 8.7% between series while Thesis fell 1.3%.
  These runs paired Thesis with TrueAsync within each series. Isolating handler cost requires
  interleaving the two Thesis handlers.

- Run `examples/http-arena.php` with one PHP process, CLI OPcache on and Xdebug off. Use the same
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
- Against `19bdc1d`, chunked POST lost throughput in all six alternating tracing-JIT Arena pairs
  (4096 connections, pipeline depth one). Pairs 1–3 used `6093d1e`; pairs 4–6 used `08254a2`
  after comment formatting and a Composer lock update. In the final three pairs, the median paired
  changes in RPS and p99 were −7.38% and +14.92% for chunked POST, −5.58% and −1.42% for the mix,
  and −6.23% and +8.65% for Content-Length POST. Across all six, the corresponding changes were
  −7.61% and +8.94%, −4.54% and +2.96%, and −4.08% and +4.16%. All 45,188,984 timed replies were 2xx.
  In six one-vCPU in-memory controls, chunked POST took 6.26 vs 6.93 µs of process CPU per request
  at the base and final medians (+10.04% at the paired median). The extra CPU cost persists without sockets.

#### Experiments with the corrected router handler on the Xeon

- The measured variant used the raw string handoff at `e99888a`, shared
  response headers and an explicit HEAD route. With the original 5 s settle and 20 s measurement,
  its median share of TrueAsync was 73.67% on one core and 68.56% on two, up 0.99 and 6.25
  percentage points from the corrected-handler starting point. Median throughput was 37,846
  vs 51,369 and 79,572 vs 115,558 replies/s. These shares are medians of three paired ratios.
  Every one of the 17,005,449 requests got a 2xx reply with no errors or reconnects.
  The one-core Thesis listener still had 1407–1471 connections queued at 6 s, and none at 21 s.

- Rejected: combining the server's decoded-byte budget and failure tracking with the chunk
  decoder. Early one-core tests of a prototype gained 1.77% at the median. The final version
  on top of the raw writer and shared headers lost 0.50%, 1.22% and 1.08% on two warmed cores.
  All 14,052,315 requests in the two-core three-way trial got 2xx.
- With the routed `baseline11` handler on one physical Xeon core, disabling Zend GC changed
  throughput by −4.2%, −4.0% and +1.1% in three pairs. With GC enabled, PHP reported 8–9
  collections, zero collected cycles and 0.10–0.13 s of collector time in 26.4 s. Keep GC enabled.
- Removing Xdebug from the project PHP image, compared with loading it with `XDEBUG_MODE=off`,
  changed one-core throughput by −0.7%, −2.4% and −1.6%. Tracing JIT was active in both.
  Median p99 was 118.8 ms with the extension loaded and 122.0 ms without it.
- Replacing tracing JIT with function JIT lost 18.8–21.8% in three one-core corrected-handler
  pairs on the Xeon. Increasing the default 2 MiB native fiber stack by one 4 KiB page to
  change its memory layout changed throughput by −2.6%, +3.1% and −4.1%.
- During the stock one-core run, 4096 established TCP connections included 1631 sockets still
  on the listen queue at 5 s and 591 at 10 s. The queue was empty at 20 s. For a steady-state
  comparison, check the sum of listener receive queues as well as the established count.
- Debian's Sury PHP 8.6.0 NTS CLI with tracing JIT reached 36,538 replies/s against 36,634
  for the project ZTS image on one Xeon core. Paired changes were −3.3%, −0.3% and +1.8%.
  Median p99 was 118.8 ms for both. The builds also differ in packaging and compiler settings.
- Rejected for the short Arena query: changing `Query` from a list of pairs to a packed list
  of alternating names and values. Longer query controls improved 13–19%, but parsing the
  nine-byte query and looking up both fields took 651 ns with pairs and 716 ns with the packed
  list at the medians. Reusing the pair from `explode()` and checking for a percent sign before
  decoding also gave mixed results across five local JIT pairs. Neither reached the TCP trials.
- Retesting only the write side of the earlier buffered-body experiment on the Xeon, a small
  fixed-length `StringBody` took one direct read and one write. The normal path already writes
  once. Against the corrected router handler, three one-core mixed pairs changed throughput
  by −0.59%, −0.05% and +4.37%. All 4,390,342 requests received 2xx. Keep the normal write path.
- Rejected for now: a new buffer operation to replace the contents and reset the cursor after
  a cached request head. Replacing the contents with the suffix saved 25 ns per short exchange
  in a local handoff control. Keeping the whole wire string and setting an offset saved about
  60 ns, but retained 4 MiB more across 4096 idle connections with long cached heads.
- Sampling the corrected handler on one Xeon core attributed about two thirds of process CPU
  to userspace. The stock process retired 0.74 instructions per cycle; no single sampled symbol
  accounted for 3% of cycles. In a separate local two-clock-read control, replacing `Instant`
  allocations with integers saved 154 ns per pair of reads, including one queued timestamp.
  This does not establish a throughput gain large enough to change the cross-package clock API.
- Rejected: keeping one `Exchange` per connection and clearing its head, decoder and response
  state before the next request. With raw string response handoff in both arms, three of six
  local routed-mix CPU pairs improved; the median reduction was 0.36%.
- A local TCP probe sampled one contended read in 127 without consuming bytes. At 4096 clients
  only 109 of 4000 samples found bytes before the keep-alive checkpoint, against 4000 afterwards.
  At 64 clients, 166 of 3000 were ready before it and 2895 afterwards. An extra read before
  yielding would usually add a syscall on this workload.
- Adapting the packed checkpoint queue to preserve the current read observer's deferred wakes
  passed the async suite. With 4096 fibers, the local CPU control saved about 0.07 µs per
  checkpoint at the paired median. Two of six pairs regressed. Keep the existing queue.
- Calling the privately owned chunk decoder's payload method directly from the server wrapper
  saved 0.8% CPU on the local chunked-only workload but cost 1.6% on the mix. It also required
  access to a protected method. Keep the public reader contract between those objects.

### HTTP Arena Async Delay memory

At `f09c601` on PHP 8.6.0 CLI Alpine (arm64, ZTS, CLI OPcache), the default 128 MiB PHP limit
exhausted a worker's heap in two runs with four workers and 32,000 configured connections.
In a 10 s run with a 320 MiB limit per worker, all 1,166,430 responses were 2xx and the workers'
PHP allocator peaks were 172–182 MiB. These peaks depend on how many connections each worker serves.

A separate one-worker probe first read a response on every connection and kept all sockets open.
At 1,024, 2,048, 4,096 and 8,000 idle connections, live PHP allocations were 30, 59, 117 and 224 MiB.
Putting all 8,000 handlers to sleep at once raised live allocations by another 3.8 MiB.
After disconnecting all clients, live allocations returned to 11.2 MiB in each of two cycles.
The allocator still held 234–236 MiB. A control on the same PHP build measured about 800 bytes
to create a bare fiber and another 16,400 bytes to start and suspend it, per fiber.
The HTTP listener starts a task for each accepted connection and keeps it across keep-alive requests.

The exchange reads the remaining connection capacity directly before committing the response.
Removing the closure that previously read it saved 408 bytes of live PHP heap per idle connection
after a GET at 64, 1024 and 2048 connections. In six alternating pairs with one pinned vCPU,
the in-memory GET control took 5.08 vs 5.02 µs of CPU per request at the medians. Two pairs slowed down.

The response writer keeps the Unix second of its cached head. With 4096 warmed writers on PHP 8.6
and CLI OPcache, retaining the integer saved 1.38 MiB of live PHP memory against retaining the wall
clock's DateTimeImmutable. In four pairs (ABBA twice), each process encoded the same fixed-length GET
response 100 times per writer. Median time per encode fell from 584 to 534 ns. Both arms formatted
Date with gmdate. The probe excludes sockets, handlers and the event loop.

## HTTP/1 codec performance

Unless a different base is given, these decisions come from PHP 8.6 TCP and in-memory comparisons against `016ed2c`.
Revisit them if a profile shows a different workload or a PHP upgrade changes the cost.

- Rejected: scanning a path for escapable bytes before calling `strtr()`. Plain short paths saved
  0.07 µs per head, paths with brackets got slower, and TCP throughput changed by only 0.5%.
- Keep the last GMT Date string and its Unix second in the worker. At `b2595f9`, six alternating
  pairs on PHP 8.6 with tracing JIT served 512 GETs per in-memory connection. Alternating one- and
  two-byte bodies missed the connection's response head cache. Reusing the date cut median exchange
  time from 5.55 to 5.15 µs (7.16% at the paired median). With identical bodies the paired change
  was +0.31%. When the clock changed on every encode, the isolated encoder cost 4.39% more.
- Rejected: caching the status line by HTTP version and status code. Creating and encoding a fresh
  response took 2.117 µs before and 2.110 µs after. The cache gave no TCP gain.
- Rejected: storing encoded field lines on each `Headers`. Reusing the same headers cut response
  encoding from 1.40 to 1.26 µs, but fresh headers went from 2.12 to 2.20 µs. Even with shared
  headers the TCP run stayed around 60k responses/s. Include fresh, shared and cloned headers in a retry.
- Rejected: special-casing GET and POST before the method token check. Tally counted 2.15% fewer
  parser instructions on a short GET. TCP throughput changed by 0.5% and batch p99 did not improve.
- Rejected: PCRE in place of `strcspn()` for forbidden bytes in a field value. With a 3 KB Cookie,
  the parser used 28% more instructions and TCP throughput fell from 49.4k to 43.5k requests/s.
- Rejected: trying `Buffer::readUntil()` before the bounded fill when the unread buffer fits within the chunk-size
  or trailer line limit. Against `0a5888f`, a buffered two-byte chunk took 5.7% less time to decode in memory.
  Splitting the CRLF of the size line across reads cost 5.7% more. Both effects held in all eight rounds.
  Across three one-worker Arena pairs on M1 Pro, median throughput was 53.2k/53.1k replies/s (base/candidate)
  on chunked POST and 57.1k/57.2k on the mix. The ranges overlapped on both workloads.
- Rejected: reading a whole first chunk with a plain size line directly from the buffer on the first body read.
  Against `7eaf938`, three HTTP Arena H1 mix pairs changed throughput by −1.6%, +33.2% and −44.3%.
  The two arms had 30–33% relative standard deviation. Neither the mix nor chunked POST showed a repeatable gain.
