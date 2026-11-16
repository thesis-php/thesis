# HTTP messages: ideas

A draft for phase 5 of the roadmap, not a decision. What survives the discussion goes to a topic
of `agents/` (the messages span `http`, `http-client` and `http-server`), and this file is deleted.

## One set of messages for both directions

`Request` and `Response` live in `thesis/http` and are used by the client and the server alike: there is no
`ServerRequest` or `ClientResponse`. The reason is proxies and gateways: in amphp `http-client` and `http-server`
have a `Request` each, and proxying means converting one into the other. Go has one `http.Request` for both
sides and it works, but its request also carries fields that mean something on the server only (`RemoteAddr`,
`TLS`, `RequestURI`): empty and meaningless in a request the client builds. We don't repeat that.

The rule: **a message holds the semantics of HTTP only, the transport goes beside it.**

```php
// thesis/http, shared by the client and the server
final readonly class Request
{
    public function __construct(
        public string $method,              // not an enum: methods are extensible (WebDAV, PURGE)
        public Url $uri,
        public Headers $headers = new Headers(),
        public Body $body = ...,            // empty
        // trailers, see below
    ) {}
}

final readonly class Response
{
    public int $status;                     // an int: codes are extensible, `Status` gives names only
    public Headers $headers;
    public Body $body;
}
```

What the server knows about the connection comes to the handler as a second argument:

```php
// thesis/http-server, the server only
final readonly class Context
{
    public SocketAddress $remoteAddress;
    public SocketAddress $localAddress;
    public ?TlsInfo $tls;
    // later: upgrade for WebSocket, 103 Early Hints, the parameters of the router
}

HttpServer\listen(
    address: '0.0.0.0:8080',
    handler: static function (Request $request, Context $context): Response {
        return new Response(status: 200, body: Body::fromString("hello {$context->remoteAddress}"));
    },
);
```

A handler that does not need the context does not declare it: PHP passes an extra argument to a closure
silently, so the simple handler stays `static fn(Request $request) => new Response(...)`.

## The client and the handler have one shape

The client is `callable(Request): Response`, the handler of the server is `callable(Request, Context): Response`
(the server as a function, as in Finagle). Then:

- middleware of the server and interceptors of the client are the same thing, `callable(Request, next): Response`:
  logging, compression, retries are written once;
- a proxy is almost one line:
  ```php
  handler: static fn(Request $request) => $client->send(
      clone($request, ['uri' => $request->uri->withHost('backend')]),
  ),
  ```
- a test calls the handler directly, without a socket, and a client pointed at a handler is an in-memory transport.

## The body

The body is where the two directions differ:

| | Outgoing (client request, server response) | Incoming (client response, server request) |
|---|---|---|
| Created by | the user | the parser of the connection |
| Length | often known | from the headers, may be unknown |
| Replay | needed (redirect 307/308, a retry on a stale keep-alive connection) | impossible |
| Lifetime | free | tied to the connection: it is not back in the pool until the body is read or closed |

`Body extends Io\Reader` with `?int $length`. Implementations:

- `Body::fromString()`: replayable, the length is known;
- `Body::fromReader($reader, length: ...)`: one-shot;
- replayable from a factory `callable(): Reader`, like `GetBody` in Go. The client replays a request only
  if its body is replayable;
- the incoming body (internal): a `Reader` over the parser or the in-memory pipe of the roadmap, with a limit
  on its size; `close()` frees or drops the connection.

A trap: `clone($request, [...])` shares one streaming body between the copies. A body is a stream, not a value:
this goes to the invariants of `http`.

A streaming response of the server (SSE, a large file) is a `Response` whose body is a pipe, and the writer
runs as a child task. Cancellation comes from the task tree: the client disconnected, the scope of the request
is cancelled.

## Independent of the protocol version

A message is the same for HTTP/1.1 and HTTP/2, otherwise grpc rewrites it:

- **the codec owns the framing headers, the message does not.** `Content-Length`, `Transfer-Encoding`,
  `Connection`, `Keep-Alive`, `Upgrade` are not in `Headers`, the length comes from `Body::$length`.
  A header and a body then cannot disagree, which closes request smuggling at the level of the model;
- `Host` and `:authority` are the authority of `$uri`, not a header;
- **trailers are in the model from the first version**, grpc needs them. Incoming trailers exist only after
  the end of the body, so they are not a value but something that becomes available after reading
  (a method of the body or a wait). Outgoing trailers are a `callable(): Headers` that the codec calls
  after the body.

## The client and the lifetime of a response

If `send()` just returns a `Response`, a user who does not read the body to the end keeps the connection.
The options:

1. `send(Request): Response`, the body is closed by `close()` or the destructor. Familiar, but it leaks.
2. Structured: `send(Request, callable(Response): T): T`, the body is closed after the callable. In the spirit
   of `async`, but awkward when the response is passed on (a proxy).

The proposal: the first one, with the incoming body bound to the scope of the task that called `send()`.
The scope ends, the body is closed, the connection goes back or is dropped. A proxy works, and a leak lasts
no longer than the task. To check on `async`: whether such a binding works without cycles (the trap of
fiber keys in `agents/fibers.md`).

## Smaller points to settle early

- **URI.** `Uri\WhatWg\Url` suits the client: strings from users, lenient parsing. But HTTP is defined by
  RFC 3986, and WhatWG normalizes (percent-encoding, dot segments): the server and request signing (SigV4)
  lose the original request-target. `OPTIONS *` and the authority-form of CONNECT are not URLs either.
  `http` probably needs `Uri\Rfc3986\Uri`, or at least the raw `$target` beside it. This goes against the rule
  of `agents/php.md`, which would then need refining.
- **`Headers`** come from the old `thesis/headers`: immutable, names case-insensitive, several values per name.
  Typed headers later, as functions.
- **1xx.** The server sends `100 Continue` by itself on the first read of the body, `103 Early Hints`
  goes through `Context`.
- **Upgrade (WebSocket)** goes through `Context` too: the handler takes the connection over, the message
  knows nothing about it.

## Security of the server

Zero CVEs cannot be promised, but whole classes of them can be made impossible by design and the rest hunted
by tests aimed at them. The CVEs of HTTP/1.1 servers (nginx, Node/llhttp, Go, Netty, Puma, amphp, Apache)
fall almost all into the classes below.

### Principles

- **Strict, not Postel.** "Be liberal in what you accept" gave most of request smuggling. RFC 9112 lets
  a recipient reject malformed input almost everywhere ("MAY reject"), and we always do: any ambiguity is `400`
  and a closed connection. No guessing what the peer meant, no resync after a parse error.
- **A sans-IO parser**, like h11 of Python: a state machine from bytes to events, without sockets and fibers.
  It can be fuzzed, run on corpora and compared with other implementations. The client parses responses with
  the same codec, so it lives in `http`.
- **Limits are on by default** with safe values, turning one off is explicit.

### Request smuggling (desync)

The server and a proxy in front of it disagree on where a request ends. Rejected:

- `Content-Length` together with `Transfer-Encoding`;
- several `Content-Length` fields or a list (`5, 5`); a value of anything but digits (no `+`, spaces, `0x`)
  or longer than 18 digits (PHP overflows to a float);
- `Transfer-Encoding` that does not end with exactly `chunked`, and any `Transfer-Encoding` in HTTP/1.0;
- in chunked: a size that is not hex or has too many digits, chunk extensions over a limit (CVEs of Go and Node
  in 2023), anything but `\r\n` after the data;
- a bare `\n` or `\r` instead of `\r\n`, obs-fold (a header continued on a line starting with a space),
  whitespace between a field name and `:`;
- a field name that is not a token, a field value with CR, LF or NUL;
- a missing or repeated `Host` in HTTP/1.1. When `Host` and the absolute-form disagree, the absolute-form wins;
- fields of trailers are never merged into the headers, and framing and routing fields (`Content-Length`,
  `Host`, `Transfer-Encoding`, ...) are forbidden there.

The codec owns the framing headers (see above), so a user cannot send a response whose `Content-Length`
disagrees with its body.

Pipelining and an unread body: the next request is parsed strictly after the end of the body of the previous one.
When the handler answers without reading the body, the server drains the rest within a limit or closes
the connection. A broken body closes the connection as well.

### Response splitting

User data ends up in headers (`Location` from the query string and the like). `Headers` validates names and
values when it is created: CR, LF or NUL in a value is a `\ValueError`, a mistake of the caller. The reason phrase
is checked the same way. With this invariant there is no "escape it later": a string with CRLF cannot get into
`Headers` at all.

### Denial of service

The main practical class for PHP.

| Attack | Defense |
|---|---|
| Slowloris (headers byte by byte) | a timeout on the whole header section, not on each read |
| a slow body | an idle timeout on reads of the body, a minimal rate |
| a slow reader of the response | a write timeout |
| a huge request line or headers | limits: ~8 KiB for the request line, ~16–64 KiB for all fields, ~100 fields |
| a huge body | a size limit, checked before `100 Continue`, not after |
| endless keep-alive or pipelining | an idle timeout, a limit of requests per connection, a bounded queue of responses |
| many connections | a limit of connections, in total and per IP |
| quadratic parsing | look for `\r\n\r\n` from where the previous search stopped, not from the start of the buffer |
| hash flooding | the limit of fields (see below) |
| ReDoS | no backtracking regular expressions in the parser: `strspn()`, `strcspn()`, `strpos()` |
| a decompression bomb | no decoding of an incoming `Content-Encoding` by default, a limited output otherwise |

The hash of PHP arrays is not randomized: thousands of colliding field names make insertion quadratic.
The limit of fields closes it, and the form parser limits its fields too (like `max_input_vars`).
A decoded body is limited by the reader that feeds `inflate_add()` 8 KiB at a time (see the roadmap).

### Numbers and paths

- Numbers (`Content-Length`, the chunk size, `Range`) are parsed by hand with a check of digits and length:
  `(int)`, `intval()` and `hexdec()` silently accept garbage or turn into a float.
- The request-target is kept raw and decoded once. The router compares decoded segments, `%2F` inside a segment
  and `%00` are rejected, nothing is decoded twice, `..` is not silently normalized. Static content depends
  on it (path traversal).
- `Host` and `X-Forwarded-*` are not trusted: `X-Forwarded-*` only from configured proxies. Host header poisoning
  (links in password reset emails) is the level of the application, the README warns about it.

### Errors and leaks

- An exception of the handler is a `500` without the message and the trace, the details go to the log only.
- A handler that fails after the response has started closes the connection: the client must not take
  a truncated response for a complete one.
- No body for `HEAD`, `1xx`, `204`, `304`. A handler that returns one there is an error, not a silent drop.
- Request data is escaped in logs (log injection).

### How to check

1. Corpora of known attacks: the tests of llhttp (Node), h11, PortSwigger's smuggling cases. Every known CVE
   becomes a test on bytes.
2. Differential testing: [HTTP Garden](https://github.com/narfindustries/http-garden) runs the same bytes through
   different servers and proxies and finds where they disagree; in 2024 it found dozens of CVEs. Our server
   belongs there.
3. Fuzzing the sans-IO parser ([nikic/php-fuzzer](https://github.com/nikic/PHP-Fuzzer)). The invariant: the parser
   either yields events or rejects the input; it never crashes, hangs or uses memory over the limits.
4. A property of the codec shared with the client: parse, serialize, parse again gives the same.
5. Slowloris, a slow body and the timeouts are tested deterministically on the virtual time of `async`,
   without sleeping.

### Out of scope here

HTTP/2 brings a class of its own (Rapid Reset, HPACK bombs, the CONTINUATION flood of 2024), it is designed
separately. TLS is the concern of `net`. At the level of the application (CSRF, XSS, CORS) the server can only
stay out of the way and give safe defaults.

## For a topic of agents

1. One `Request`/`Response` in `http` for the client and the server, the transport in `Context`.
2. The client and the handler are functions of one shape, middleware is shared.
3. `Body` is a `Reader` with a length and (non-)replayability. The codec owns the framing headers.
4. Trailers are in the model from day one.
5. A strict sans-IO codec in `http`, shared by the client and the server: any ambiguity is a `400` and a closed
   connection. `Headers` rejects CR, LF and NUL on creation. Limits and timeouts are on by default.

## Open questions

1. The URI: RFC 3986 or WhatWG, or both (a URL for the client, the raw target on the server)?
2. The lifetime of a response body in the client: a plain return bound to the scope, or a callable?
