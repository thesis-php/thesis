# thesis/http-protocol

Clients and servers share the HTTP/1 codec for heads and bodies. The codec uses the message types in `thesis/http`.
It needs 64-bit PHP for Content-Length and chunk sizes. Read `README.md` for the codec API.
The HTTP/1 API lives in `Http1`. Its implementation details live in `Internal\Http1`.

## Invariants that must not be broken

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
  the zero chunk and the entire trailer section. At completion, `discard()` returns the saved fields.
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

## Decisions

- Rejected: a lazy `Headers` proxy. If its initializer suspends, another reader of the proxy can see an
  uninitialized object. The codec publishes a complete immutable `Headers` at the end of the trailer section.
- Trap: `toRawString()` of `Uri\Rfc3986\Uri` expands an IPv6 host (`[::1]` becomes `[0000:...:0001]`,
  PHP 8.6.0), while `getRawHost()` returns it as it was written. The codec writes `Host` and the target
  from the getters, never from `toRawString()`.
- A head is a value with a private constructor, two static constructors (`parse()` from bytes, `of()` from
  a message) and `toString()`. So every head is either what a peer has sent or what a message allows.
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

### HTTP/1 codec performance

These decisions come from PHP 8.6 TCP and in-memory comparisons against `016ed2c`.
Revisit them if a profile shows a different workload or a PHP upgrade changes the cost.

- Rejected: scanning a path for escapable bytes before calling `strtr()`. Plain short paths saved
  0.07 µs per head, paths with brackets got slower, and TCP throughput changed by only 0.5%.
- Rejected: caching the status line by HTTP version and status code. Creating and encoding a fresh
  response took 2.117 µs before and 2.110 µs after. The cache gave no TCP gain.
- Rejected: storing encoded field lines on each `Headers`. Reusing the same headers cut response
  encoding from 1.40 to 1.26 µs, but fresh headers went from 2.12 to 2.20 µs. Even with shared
  headers the TCP run stayed around 60k responses/s. Include fresh, shared and cloned headers in a retry.
- Rejected: special-casing GET and POST before the method token check. Tally counted 2.15% fewer
  parser instructions on a short GET. TCP throughput changed by 0.5% and batch p99 did not improve.
- Rejected: PCRE in place of `strcspn()` for forbidden bytes in a field value. With a 3 KB Cookie,
  the parser used 28% more instructions and TCP throughput fell from 49.4k to 43.5k requests/s.

## Tests

- A rejection is asserted with its status and its whole message (`rejection()` in `tests/Http1/helpers.php`),
  so a test fails when an input is rejected for a reason other than the one the test names.
- Keep regression cases for ambiguous heads in `RequestHeadParseTest` and malformed chunks in `ChunkedTest`.
- `ScriptedReader` returns its bytes in pieces and fails on demand: a codec is tested with every piece size
  and with a failure before every read.
