# thesis/http

Clients and servers share the same request and response types so proxies can forward unread bodies and trailers.

## Invariants

- Client middleware passes an outgoing request on each call to the next handler. Server middleware can call
  the next handler at most once, with the same incoming request bound to the call.

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
  A codec depends on the message package. Messages use no codec types.
- An eligible HEAD or 304 response can carry `Content-Length` in its headers as representation metadata.
  The codec validates the field against the request method and response status.
- `Headers` cannot hold a name that is not a token, or a value with a control character other than the tab.
  The class itself prevents response splitting, there is no "escape it later". Its state is private
  and changes only through its own methods.
- PHP turns a numeric name of `Headers` (`1`) into an int key of the array. The keys are cast back to strings
  when iterating. The codec reads the underlying array and casts names when it writes them.

## Decisions

- **`Uri\Rfc3986\Uri`, not `Uri\WhatWg\Url`.** HTTP is defined in terms of RFC 3986. `Uri\Rfc3986\Uri` keeps
  the path and the query as they were sent (`getRawPath()`, `getRawQuery()`), which a router, a proxy
  and a request signature need. It also holds a reference without a host: an HTTP/1.0 request without `Host`,
  `OPTIONS *` as an empty path, `CONNECT` as `//host:port`. `Uri\WhatWg\Url` rewrites what it parses: it drops
  tabs and new lines (`/a\tb` becomes `/ab`), removes dot segments, turns `\` into `/`, changes the host,
  and it requires a scheme and a host. The price of RFC 3986 is the bytes that browsers send unescaped,
  see above. A client that accepts URLs typed by people (an IDN host, spaces) must convert them before
  it makes a `Request`: that is a task for `http-client`.
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
