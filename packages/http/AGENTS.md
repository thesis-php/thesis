# thesis/http

Clients and servers share `Request`, `Response` and `Headers`. The `Http1\` codec reads and writes heads
and handles fixed-length and chunked bodies. The server owns connections, fibers and timeouts in `thesis/http-server`.
Read `README.md` for the codec API and `ideas/http.md` for client design notes.

## Invariants that must not be broken

### Messages

- A message carries only the meaning of HTTP and nothing about the connection: no version, no reason phrase,
  no framing. The head formatter rejects the fields in `Fields::OF_CONNECTION` in message headers,
  except for the representation length described below. On requests it also rejects `Host`, which comes
  from the URI. A parser extracts framing before handing headers to the application. This keeps
  framing independent of application headers when a proxy forwards a message.
- The properties of `Request` and `Response` are `public(set)` for `clone()`, so their constructors check nothing:
  a copy would bypass the check. The codec checks what must hold on the wire (a method is a token),
  because every message passes through it.
- `Headers` cannot hold a name that is not a token, or a value with a control character other than the tab.
  The class itself prevents response splitting, there is no "escape it later". Its state is private
  and changes only through its own methods.
- PHP turns a numeric name of `Headers` (`1`) into an int key of the array. The keys are cast back to strings
  wherever they leave the class, and a test covers it.
- A message clone shares the reader and its position unless the clone supplies a new reader.
  Pass an unread reader to the sender. A short body requires closing the connection before another head is written.
- The `$trailers` property reads the current reader state without I/O or suspension. `null` means the fields are
  unavailable. An empty `Headers` value means the message has no trailers.
  `discardBodyAndReadTrailers()` reads to EOF even if the trailer fields are already known.

### The codec

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
- Trailers stay separate from headers. `ChunkedReader::$trailers` stays null until the reader consumes the zero
  chunk and the entire trailer section.
- The HTTP/1.1 formatter uses chunked framing when trailers are present, announced by `Trailer`, or still pending.
  HTTP/1.0 responses and responses without a body reject trailers.
- `FixedLengthReader` consumes exactly the declared body bytes, preserves the next message in the connection
  buffer and reports premature connection EOF as `ReadFailed`. The connection owner controls its lifetime.
- `FixedLengthReader` and `ChunkedReader` throw `ConcurrentRead` on overlapping reads so the pending read
  retains exclusive access to the body boundary. The shared buffer can gain bytes while the source waits,
  so another read could otherwise consume them without calling the source.
- Whether a response has a body is decided by the method of the request and the status
  (`ResponseHead::hasBody()`), never by the fields. Only a response without a body may have `content-length`
  as a header: the length the body would have (`HEAD`, 304). It follows the same decimal syntax on input
  and output. Status 1xx and 204 forbid the field even for `HEAD`.
- A chunked body that is cut short fails with `ReadFailed`. `UnexpectedEof` from the connection must not reach
  the caller: for the caller it means a clean end of the body.
- After a malformed chunk or trailer, every later read reports the failure. The decoder may have
  consumed the offending line, so retrying the parser could interpret the next line as a fresh chunk.
  Check the trailer count as each line arrives, even when the final empty line has not arrived.
- `ChunkedReader` changes its state only when a step is complete, so a cancelled read continues from the same
  step: the CRLF after a chunk, the size line, each line of the trailers.
- An error message quotes bytes from a peer through `Internal\quote()`: escaped (log injection) and cut
  to 64 bytes.
- The number of fields is limited before the fields get into an array: PHP arrays do not randomize their hash,
  so thousands of colliding names would make the parsing quadratic.

## Decisions

- **`Uri\Rfc3986\Uri`, not `Uri\WhatWg\Url`.** HTTP is defined in terms of RFC 3986. `Uri\Rfc3986\Uri` keeps
  the path and the query as they were sent (`getRawPath()`, `getRawQuery()`), which a router, a proxy
  and a request signature need. It also holds a reference without a host: an HTTP/1.0 request without `Host`,
  `OPTIONS *` as an empty path, `CONNECT` as `//host:port`. `Uri\WhatWg\Url` rewrites what it parses: it drops
  tabs and new lines (`/a\tb` becomes `/ab`), removes dot segments, turns `\` into `/`, changes the host,
  and it requires a scheme and a host. The price of RFC 3986 is the bytes that browsers send unescaped,
  see above. A client that accepts URLs typed by people (an IDN host, spaces) must convert them before
  it makes a `Request`: that is a task for `http-client`.
- Trap: `toRawString()` of `Uri\Rfc3986\Uri` expands an IPv6 host (`[::1]` becomes `[0000:...:0001]`,
  PHP 8.6.0), while `getRawHost()` returns it as it was written. The codec writes `Host` and the target
  from the getters, never from `toRawString()`.
- A method is a string and a status is an int, so callers can use custom values such as `PURGE` and 299.
  `Method` and `Status` give these values names such as `Method::GET` and `Status::OK`.
  HTTP/1 writes the phrase from `Status::reasonPhrase()`, or an empty phrase when the lookup returns null.
  Rejected: a list of all the methods (`METHODS`). It invites a check with `in_array()`, which refuses valid methods.
- `Headers::get()` joins the lines with a comma, as RFC 9110 defines. Rejected: returning the first line
  (`Header.Get` in Go), which silently ignores a second `X-Forwarded-For` or `Authorization` line.
- `Headers` stores string values. Typed field descriptors can wait until callers need shared parsing and formatting.
- `Headers` stores names in lowercase (HTTP/2 requires it). The HTTP/1.1 codec writes them as `Content-Type`
  (`ucwords()` by hyphens): lowercase names are valid, but some peers compare names case-sensitively.
  So `ETag` is written as `Etag`, as Go does.
- `Request` and `Response` expose a `Reader` through `$body` and a separate `$trailers` property beside `$headers`.
  A message constructed without a reader gets its own empty `StringReader`. The wire codec enforces body boundaries.
- Rejected: a separate HTTP `Body` wrapper. `Reader` carries the bytes, and the HTTP/1 codec already knows
  which reader enforces the message boundary.
- Rejected: lazy reader factories and replay on the body. They make reading responsible for opening resources,
  including races between a suspended open and closing the body. Revisit fresh readers when a client needs to
  resend a body on redirects or retries.
- Rejected: using `Once` for incoming trailers. Reading before EOF must leave later reads able to see the final fields.
  `Once` can cache a premature exception or an unavailable value. A trailer callback reads ready state from
  the current reader and runs without caching or waiting.
- Rejected: a lazy `Headers` proxy. If its initializer suspends, another reader of the proxy can see an
  uninitialized object. The codec publishes a complete immutable `Headers` at the end of the trailer section.
- The trailer hook stores a normalized closure in a private readonly property. The constructor initializes
  that property, and `clone()` allows the setter to replace it on the copy. An ordinary write fails.
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
- Rejected: `Headers` that a parser validates once and then creates without a check. The parser checks
  the fields to throw `MalformedMessage`, and the constructor of `Headers` checks them again. This costs
  a second validation of each name and value, until a benchmark shows that it matters.

## Tests

- A rejection is asserted with its status and its whole message (`rejection()` in `tests/helpers.php`),
  so a test fails when an input is rejected for a reason other than the one the test names.
- Keep regression cases for ambiguous heads in `RequestHeadParseTest` and malformed chunks in `ChunkedTest`.
- `ScriptedReader` returns its bytes in pieces and fails on demand: a codec is tested with every piece size
  and with a failure before every read.
