# thesis/http

Clients and servers share the same request and response types so proxies can forward unread bodies and trailers.

The public client and server entry points live beside the message types. Their options and middleware live in
`Client` and `Server`. Implementation details live under `Internal`.

Before HTTP/1 performance work, read [performance.md](performance.md) for measurements and rejected changes.

## Invariants

- Client middleware passes an outgoing request on each call to the next handler. Server middleware can call
  the same next handler again after it returns or throws, with the same request and context. Reject overlapping
  calls to the same continuation and expire it when its own `handle()` exits. A public `Server\Pipeline`
  wraps any handler and keeps delegation state local to each middleware invocation. Rejected: passing one mutable
  cursor to every layer. If an inner layer returns early, a second call from the outer layer could skip it.
- `Server\Pipeline::wrap()` returns the original callable for an empty iterable. Collect middleware once in
  iteration order, including values with repeated keys.
- Allocate one continuation per middleware invocation.
  Comparing `9ffb998` with the initial `Next` in `d30cffb`, Tally on PHP 8.6 aarch64 measured 0 B of extra peak
  memory instead of 160 B for the empty pipeline. In ten alternating pairs with CLI OPcache off, its median
  dispatch time fell from 390 ns to 186 ns. Four layers over an in-memory HTTP/1 connection showed no clear time gain.
- Server retries preserve the request's body handoff. Keep incoming bodies streaming. Once a layer takes the body,
  calling that layer again cannot give it a fresh reader, even when the body implements `ReplayableBody`.
  Retry work that uses a decoded DTO after deserialization, where the caller can account for side effects.

- On requests and responses, `takeJson()` shares the single handoff with `takeBody()`, including when decoding fails.
- Messages share `EMPTY_BODY` for an empty payload without trailers. Each message still owns its body handoff.
- Trailer fields may be pending until the reader finishes.
- All metadata copies of a message share one body handoff. A replacement body has its own handoff.
  Dropping the last copy closes an unclaimed body. After handoff the recipient owns its cleanup.
- Changing metadata leaves the original intact. Replacing the payload replaces its trailers as well.
- `readAll()` returns the whole body or fails once bytes have been consumed. Streaming implementations
  can extend `StreamingBody` to enforce this across partial reads and keep exclusive access until a read finishes.
- Whole-body collection limits payload bytes after decoding (8 MiB by default). When an active collection
  fails or is cancelled, close the reader to release its resources. A competing read leaves the owner alone.
- A message carries the meaning of HTTP. Protocol version, reason phrase and framing belong to the codec.
  The codec uses the shared message types. Messages use no codec types.
- An eligible HEAD or 304 response can carry `Content-Length` in its headers as representation metadata.
  The codec validates the field against the request method and response status.
- `Headers` cannot hold a name that is not a token, or a value with a control character other than the tab.
  The class itself prevents response splitting, there is no "escape it later". Its state is private
  and changes only through its own methods.
- PHP turns a numeric name of `Headers` (`1`) into an int key of the array. The keys are cast back to strings
  when iterating. The codec reads the underlying array and casts names when it writes them.

## Decisions

- Open: which factories, if any, should create outgoing requests. Use the request constructor for now.

- Keep one `PendingBody` per message and share it with metadata copies. Rejected: binding two body
  properties by reference and creating the owner on the first clone. This split cleanup between two
  destructors and made ownership depend on PHP preserving the reference across a clone.
  Bypassing both owners in a chunked POST probe saved 6.42% CPU at the paired median, but the probe
  omitted shared ownership. The version that preserved it has no measured gain.

- **`Uri\Rfc3986\Uri`, not `Uri\WhatWg\Url`.** HTTP is defined in terms of RFC 3986. `Uri\Rfc3986\Uri` keeps
  the path and the query as they were sent (`getRawPath()`, `getRawQuery()`), which a router, a proxy
  and a request signature need. It also holds a reference without a host: an HTTP/1.0 request without `Host`,
  `OPTIONS *` as an empty path, `CONNECT` as `//host:port`. `Uri\WhatWg\Url` rewrites what it parses: it drops
  tabs and new lines (`/a\tb` becomes `/ab`), removes dot segments, turns `\` into `/`, changes the host,
  and it requires a scheme and a host. The price of RFC 3986 is the bytes that browsers send unescaped,
  see above. A client that accepts URLs typed by people (an IDN host, spaces) must convert them before
  it makes a `Request`: that is a task for the HTTP client.
- A method is a string and a status is an int, so callers can use custom values such as `PURGE` and 299.
  `Method` and `Status` give these values names such as `Method::GET` and `Status::OK`.
  HTTP/1 writes the phrase from `Status::reasonPhrase()`, or an empty phrase when the lookup returns null.
  Rejected: a list of all the methods (`METHODS`). It invites a check with `in_array()`, which refuses valid methods.
- `Headers::get()` joins the lines with a comma, as RFC 9110 defines. Rejected: returning the first line
  (`Header.Get` in Go), which silently ignores a second `X-Forwarded-For` or `Authorization` line.
- `Headers` stores string values. Typed field descriptors can wait until callers need shared parsing and formatting.
- A repeated single string field can reuse its checked lines. Remember one exact input name and value
  up to 1 KiB together. Save the two strings by value because a caller's input array can contain references.
- `Headers` stores names in lowercase (HTTP/2 requires it). The HTTP/1.1 codec writes them as `Content-Type`
  (`ucwords()` by hyphens): lowercase names are valid, but some peers compare names case-sensitively.
  So `ETag` is written as `Etag`, as Go does.
- `StringBody::$size` reads the string length through a hook. In a PHP 8.6 microbenchmark, storing the length
  made construction with one size read 3% faster and with two reads 12% faster. Construction alone cost 13% more.
  Storing both size and trailer presence did not give a repeatable gain on the tracing-JIT Arena mix.
  In five alternating pairs before the time limit, the candidate lost three; the median paired change was −9.5%.
- A request parses its query on first access. A clone with a new URI parses its own query.
  Replacing the query on a clone updates the URI that the codec writes.
- Rejected: PSR-7 as the shared message contract. `StreamInterface` permits sequential reads, but an arbitrary
  implementation can block the event loop. PSR-7 has no standard access to trailers, which can arrive at EOF.
  It lets a request's `Host` differ from its URI and requires preserving header name case. The codec derives
  `Host` from the URI, and `Headers` stores lowercase names. Interoperability belongs in adapters at the
  application boundary, where the adapter can define which streams and message fields it supports.
- Each reader opened by `ReplayableBody::fresh()` can itself open an independent reader for another attempt.
  A general body is one-shot. Reading a streaming body never opens a replacement stream.
- Rejected: using `Once` for incoming trailers. Reading before EOF must leave later reads able to see the final fields.
  `Once` can cache a premature exception or an unavailable value. The body owns its final fields and exposes them without advancing the reader.

## Client

- Only return a persistent connection with an empty input buffer at a complete message boundary.
  Drop the body’s ownership before any later read, close or destruction can touch a reused socket.
- Response copies share one body handoff. The reader that owns the lease cannot be cloned: two owners could
  release or close the same socket after another request has acquired it.
- Reserve idle sockets per scheme, host and effective port. Probe before reuse. EOF, pending data and
  transport errors retire an idle socket. The transport leaves retry decisions to middleware.
- A close-delimited response starts with any bytes the head reader already buffered.
- Reading through EOF finishes the lease. Reading trailers delegates to the underlying body and never touches
  a released socket. The transport takes the upload once and closes it on every exit.
- A per-origin permit covers dialing, sending and the lifetime of the response body. Release it on every exit,
  including cancellation, early close and destruction. Reuse the most recently idle socket for an origin and
  evict the oldest idle socket across all origins at capacity.
- Build the middleware chain once per client. A handler outside redirects sees one application request.
  A handler inside redirects sees each destination. Credential middleware must check that destination.
- Resolve Location against the current URI. Drop private and unrecognized fields across origins and refuse HTTPS
  downgrades. Return the last response when following would require replaying a one-shot body.
- Retry only by explicit policy. Idempotence and a `ReplayableBody` are both required. After returning the
  response head, a download failure belongs to the caller.
- Decode only the encoding the middleware negotiated. Keep explicit negotiation, partial responses and
  bodyless representation metadata intact. Limit compressed input per inflate step and verify the stream end.
- A proxy connector owns its CONNECT headers. The TLS peer name after CONNECT comes from the origin.
- A multipart upload reads parts in order. Its total size includes boundaries and headers and is known only
  when every part has a known length. Keep one-shot readers out of automatic replay.

## Server

### Invariants that must not be broken

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

- The Router constructor calls a private register() once per route.
  Registration parses the pattern once and indexes the route by each method. All requests share them.
  `Router::$list` contains each registered Route once in input order.
- Import the PHPStan types `Handler` and `HandlerClosure` from `Server` for server handlers. Write route handler signatures inline.
  Both constructors accept a callable and store its closure.
- Route handlers receive the request and the connection context. routeMatch($request) reads the active match from a WeakMap keyed by request identity.
  The local type in routeMatch() is needed because PHPStan 2.3.7 ignores dynamicConstantNames for object values.
  RouteMatch holds the registered Route. Each match keeps its own parameters when nested routers handle the same request.
- A method can be a numeric token. PHP casts decimal string array keys to integers, so convert method keys back to strings in MethodNotAllowed. Dispatch joins that list for the Allow header.
- Resolve RequestStarted, RequestEnded, ConnectionAccepted and ConnectionClosed once per listener with Dispatcher::for().
  Dispatch error events directly when the error occurs.
  The router checks for listeners before creating RouteMatched.
  RequestStarted, RouteMatched and RequestEnded carry the same Request as the handler. Correlate these events by request identity. A cached request head and URI can serve several keep-alive requests.
- Configure the error handler on the server. Middleware and the router receive the same Context, which exposes the configured ErrorHandler as $errorHandler.
  The default error page contains only the status and its standard reason phrase. The router owns Allow. Error handlers format responses before the final head. If formatting a 500 fails,
  preserve the original failure for ConnectionHandlingFailed and attempt an empty 500 only before writing starts.
- Match a requirement against its parameter value. Wrapping fragments together in capturing groups
  changes numbered backreferences and can mistake user-named captures for route parameters.
  Compound segments can share one expression when each parameter is unconstrained or repeats one
  supported character class. Use lazy repetitions to keep the shortest complete split. Fall back
  to independent matches for other fragments and when the combined match hits a PCRE limit.
  On PHP 8.6 aarch64, lookup of a 2048-digit serial followed by two letters took 8.15 µs instead
  of 1.57 ms (eight alternating pairs). Mixed lookup at 2048 iterations used 102.79M instructions
  against 111.27M with independent requirements throughout, and 4,800 more bytes of peak memory.
- Register `[/]` with the route. Trimming request paths would change the value captured by a remainder.
- At the root, an omitted suffix can follow an empty literal segment: `//{id?}` also matches `/`.
- Match a complete path before checking the case-sensitive method. A more specific path owns its 405.
  On that path, try the exact method, GET for HEAD, then the null-method fallback with the original request method.
- Route an absolute URI with an empty path as `/`. Keep the empty path for `OPTIONS *` and authority-form `CONNECT`.
- Patterns contain decoded literals. Reject encoded slashes and NUL before decoding the raw request path once.
  Preserve empty segments, plus signs and dot segments.

### Decisions

- Exact paths have their own table. Dynamic paths share segment prefixes. Try literal children before
  expressions, ordinary parameters and the remainder. Only try another branch after an incomplete path.
  After registration, index a final plain parameter by prefix only when its parent has no literal or expression children.
  Record which nodes have only literal children so lookup can skip all fallback checks there. The exact
  table stores the route and match per method, including HEAD for GET when there is no explicit HEAD.
  Together these changes cut Tally instructions for mixed lookup from 120.44M to 105.19M at 2048
  iterations on PHP 8.6 aarch64. Peak memory in that benchmark grew from 785,552 to 817,480 bytes.
  Rejected: using a compressed tree or generated PHP for every path. On small and medium sets of
  simple routes the tables won mixed lookup. Generated PHP won the large two-parameter case.

- The constructor takes the configuration and handler. Bind the endpoint in `start()` so a DI container can build the server
  without opening sockets and the application sees a bind failure at startup.
- An integer endpoint in `Config` is a TCP port on `127.0.0.1`. Strings starting with `/` are absolute Unix paths.
  Other strings must be numeric TCP addresses with a port and brackets around IPv6. A relative path needs `UnixEndpoint`.
- Both endpoint types own their backlog. TCP owns `reusePort` and `noDelay`. TLS, limits and timeouts live on `Config`.
- Like the AMQP client, the server starts a daemon in the group passed to `start()`. It returns once the daemon
  has registered shutdown. The owner calls `stop()` from outside request handlers because it waits for every active exchange.
- One callback on the existing loop watches each socket for a kernel shutdown event on epoll, or readability on other backends
  and TLS. After the body decoder finishes, probe the socket and keep any pipelined byte for the next head.
  Pause the callback while the decoder owns the stream or the next head has buffered bytes. Recheck once
  after the decoder completes or the next exchange starts. Cancel the connection task on EOF or a read failure. Rejected: a group and reader daemon per exchange. They roughly halved
  short GET throughput even for synchronous handlers (see performance.md). With the lazy scopes in
  `499def1`, an unused group raised the median in-memory plaintext GET from 3.67 to 5.01 µs.
  A handler can open a group for parallel work that finishes before it returns. A streaming body can
  produce bytes as the server reads it. Revisit per-exchange groups if middleware needs an owned task
  to keep running after the handler returns until the server finishes writing the response.

- The listener registers each connection before spawning its task so shutdown sees it even before TLS negotiation.
  It drains active exchanges on shutdown.
  Each connection closes its own transport.
  The head reader and body decoders share the connection buffer so bytes after a body survive for the next request.
  Each accepted request runs in a fresh exchange. The connection is active until that exchange returns or throws.
  When shutdown starts, the listener calls `stop()` on each
  connection before returning control to handlers. An idle connection closes immediately. An active
  connection finishes the current exchange. Stopping sets its remaining request count to zero. The exchange
  reads the same request limit when deciding which persistence headers to send.
  Tests and benchmarks run the same connection loop with an in-memory reader and writer.
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
- Take a response string through the same one-shot handoff as a reader. The server can write a
  length-framed string of up to 8 KiB with its head without creating a `StringBody`.
  Reading a stream can wait for the peer to receive the head, so send the head before reading
  a streaming body. A small fixed-length echo can share
  that write when the connection already has all its remaining input. A large string is already in memory:
  send it in blocks of up to 64 KiB to amortize the socket write and its timer. Keep streaming reads at 8 KiB.
- Each connection reuses its last encoded length-framed response head up to 1 KiB for a string body when its length,
  status, version, persistence and ordered fields match. HEAD and CONNECT have their own framing rules.
  A string body with trailer fields uses the head formatter. The formatter reads a custom body's trailer state once.
- A fixed-length body already in the buffer has passed the size check and cannot wait on the transport.
  Its fixed-length reader still tracks unread bytes. Wrap it when the first read must send `100 Continue`.
- A partially buffered fixed-length body still needs failure tracking and may need to send 100 Continue.
  Its declared length has passed the size limit, so the server passes a running decoded-byte budget to
  `BodyReader` only for chunked input.
- Close after an unread body. Draining it would compete with any outstanding application read and would need
  a separate size limit and deadline.

## HTTP/1 codec

Clients and servers share the HTTP/1 codec for heads and bodies. The codec uses the message types in `thesis/http`.
It needs 64-bit PHP for Content-Length and chunk sizes. The codec lives in `Internal\Http1`. The client and CONNECT proxy share
the response-head reader so informational heads count toward the same byte limit as the final head.

### Invariants that must not be broken

- Strict, not liberal. Any input that two recipients could understand differently throws `MalformedMessage`:
  nothing is guessed or repaired, and nothing is read after it. Rejected: a bare CR or LF, obs-fold, a space
  before a colon, a name that is not a token, a control character in a value, `Content-Length` together with
  `Transfer-Encoding`, a `Content-Length` that is repeated, is a list or is not 1 to 18 digits, a transfer coding
  other than exactly `chunked` (`gzip, chunked` too), any `Transfer-Encoding` in HTTP/1.0, a missing
  or repeated `Host` in HTTP/1.1, a `Host` that is not a host with an optional port, a chunk size that is not
  1 to 15 hexadecimal digits, anything but CRLF after the data of a chunk, a framing field in trailers.
- A parser changes one thing: in a target that is a path it percent-encodes `[ ] \ ^ `` ` `` { | }`.
  Browsers send these bytes unescaped (`?filter[name]=x`), and `Uri\Rfc3986\Uri` cannot hold them.
- No backtracking regular expressions. No `(int)`, `intval()`, `hexdec()` or `is_numeric()` on bytes from a peer
  before the bytes are checked: first `strspn()` or `strcspn()` over a fixed set, then the cast.
- Both head constructors check each Upgrade protocol as a token with an optional slash and version token.
  Upgrade bypasses `Headers` when written, so its arguments need the same check as incoming values.
- The fields that `Connection` names are removed together with `Fields::OF_CONNECTION`: they are meant
  for this hop only. This happens while parsing, before any code adds a header, so a client cannot make a proxy
  drop a header that the proxy has added (the hop-by-hop attack).
- Trailers stay separate from headers. `ChunkedReader::$isComplete` becomes true after the reader consumes
  the zero chunk and the entire trailer section. `trailers()` returns the saved fields at completion
  and throws while they are pending.
- The HTTP/1.1 formatter uses chunked framing when trailers are present, announced by `Trailer`, or still pending.
  HTTP/1.0 responses and responses without a body reject trailers.
- `FixedLengthReader` consumes exactly the declared body bytes, preserves the next message in the connection
  buffer and reports premature connection EOF as `ReadFailed`. Closing either HTTP/1 decoder leaves the
  transport to its connection owner.
- `FixedLengthReader` and `ChunkedReader` throw `ConcurrentRead` on overlapping reads so the pending read
  retains exclusive access to the body boundary. The shared buffer can gain bytes while the source waits,
  so another read could otherwise consume them without calling the source.
- The request method and response status decide whether a response has a body. For eligible HEAD and 304,
  `Content-Length` stays in `Headers` as representation metadata and `ResponseHead::$length` remains zero.
  Representation length has the same 18-digit bound on input and output. Status 1xx and 204 forbid
  sending it even for HEAD.
- A chunked body that is cut short fails with `ReadFailed`. Return `null` only after the last chunk and all trailers.
- After a malformed chunk or trailer, every later read reports the failure. The decoder may have
  consumed the offending line, so retrying the parser could interpret the next line as a fresh chunk.
  Check the trailer count as each line arrives, even when the final empty line has not arrived.
- `ChunkedReader` changes its state only when a step is complete, so a cancelled read continues from the same
  step: the CRLF after a chunk, the size line, each line of the trailers.
- An error message quotes bytes from a peer through `Internal\quote()`: escaped (log injection) and cut
  to 64 bytes.
- The number of fields is limited before the fields get into an array: PHP arrays do not randomize their hash,
  so thousands of colliding names would make the parsing quadratic.
- The head formatter rejects `Fields::OF_CONNECTION` in message headers, with the representation-length
  exception above. On requests it also rejects `Host`, which comes from the URI. The parser extracts framing
  before handing headers to the application, so forwarding a message leaves each hop in charge of its framing.

### Decisions

- Rejected: a lazy `Headers` proxy. If its initializer suspends, another reader of the proxy can see an
  uninitialized object. The codec publishes a complete immutable `Headers` at the end of the trailer section.
- Trap: `toRawString()` of `Uri\Rfc3986\Uri` expands an IPv6 host (`[::1]` becomes `[0000:...:0001]`,
  PHP 8.6.0), while `getRawHost()` returns it as it was written. The codec writes `Host` and the target
  from the getters, never from `toRawString()`.
- A head is a value with a private constructor, two static constructors (`parse()` from bytes, `of()` from
  a message and the body taken by the sender) and `toString()`. So every head is either what a peer has sent or what a message allows.
  The server and the client read from the same value what they must do after they write the head (chunk
  the body, close the connection): it is not decided twice.
- `Framing` is an enum, not `?int $length` with flags. A response to `HEAD` (no body, maybe a `content-length`
  header) differs from a body of zero bytes (`Content-Length: 0`), and the body of a response without framing
  fields lasts until the connection is closed.
- A response is written in the version of its request (as Go does), so a head decides by its own version:
  HTTP/1.0 writes `Connection: keep-alive` to stay open and has no chunks, HTTP/1.1 writes `Connection: close`
  to close.
- An empty line before a request line is rejected, though RFC 9112 lets a server skip one. A server that wants
  to skip the line must do it before it calls `parse()`.
- A status line without a reason phrase and without the space before it (`HTTP/1.1 200`) is accepted:
  servers send such lines, and they cannot be understood in two ways.
- An absolute target (`GET http://host/path`) wins over `Host`, as RFC 9112 says, and must be a valid URI
  as it was sent: the bytes that browsers leave unescaped are percent-encoded only in a path.
  Only a client of a forward proxy sends an absolute target.
- Chunk extensions are parsed and ignored, as RFC 9112 requires, and a chunk size line is limited to 256 bytes.
  Without a limit a peer could send kilobytes of extensions with every byte of the body
  (CVE-2023-39326 in Go).
- `MalformedMessage` extends `Thesis\Io\IoException`: a chunked body throws it from `Reader::read()`, whose
  contract is "an `IoException` on any failure". Its `$status` is the status of the response that a server sends
  to such a request (400, 431, 501, 505).
- Rejected: one push parser that takes bytes and emits events (h11). A head is parsed by a pure function
  from a string, which the caller has cut at the empty line (`Buffer::fillUntil()` does it with a limit
  and without rescanning). A chunked body is parsed by a `Reader` that wraps a `Reader`. Both run in tests
  on strings, with no socket and no fiber, and a timeout is just the cancellation of the caller.
- Rejected: one regular expression to split and validate each field line. It made a request with a 3 KB
  cookie slower and gave no consistent gain across header sizes on PHP 8.6.
- The codec checks wire syntax before interpreting framing. It passes message fields to the normal `Headers`
  constructor, which checks their names and values. Rejected: an unchecked header constructor, which would
  let callers put invalid fields on the wire.

### Tests

- A rejection is asserted with its status and its whole message (`rejection()` in `tests/Internal/Http1/helpers.php`),
  so a test fails when an input is rejected for a reason other than the one the test names.
- Keep regression cases for ambiguous heads in `RequestHeadParseTest` and malformed chunks in `ChunkedTest`.
- `ScriptedReader` returns its bytes in pieces and fails on demand: a codec is tested with every piece size
  and with a failure before every read.
