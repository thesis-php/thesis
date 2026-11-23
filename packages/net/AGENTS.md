# thesis/net

This package provides non-blocking TCP and Unix sockets, TLS and asynchronous DNS. Every wait goes through
thesis/async, so the task tree controls cancellation. `Socket` implements the thesis/io `Reader`, `Writer`
and `Closer` interfaces. DNS messages use `Buffer` for encoding and decoding.
Read `README.md` for the API and its guarantees.

## Invariants that must not be broken

- No method takes a timeout or a cancellation: every wait goes through a `StreamWatcher` of thesis/async.
  Only DNS has timeouts of its own (`timeoutOr()` per server), because the protocol defines them.
- A wait on a stream goes through the `StreamWatcher` of its owner, and `close()` calls `StreamWatcher::close()`
  before `fclose()`: the kernel drops a closed descriptor, and the waiting fiber would never wake up.
  `Socket` and `Server` take an open stream and clear the stored resource on close.
  After any wait, re-read `$this->stream`: it may have been closed meanwhile, even if the wait returned true.
- Read first, wait only on an empty read, never the other way round. TLS keeps decrypted bytes inside OpenSSL
  where the event loop cannot see them, so waiting for readability first would hang with data pending.
  The PHP read buffer is off (`stream_set_read_buffer(0)`) for the same reason and so that `enableTls()` finds
  no plaintext bytes read ahead.
- Every stream gets a context of its own (`stream_context_create()`): without one PHP uses the default context,
  shared by every stream, and `enableTls()` setting options on it leaks them (trusted CAs!) into other connections.
  Accepted sockets share the context of their server: `ServerTls` captures the peer certificate only when the handshake
  requires one (`clientCaFile`), and `TlsInfo::fromStream()` reads it right after the handshake, before any suspension.
  Otherwise the certificate in the shared context may be another connection's.
- A write that has sent a part of its bytes and then fails or is cancelled closes the socket. A write that has sent
  nothing leaves it intact. A failed or cancelled handshake closes the socket.
- Writes and half-closes take their turn before pacing or touching the socket. The current writer hands its
  turn to the next waiting fiber so a new caller cannot overtake either one. After an `fwrite()` that sent
  nothing, the retry begins with the same bytes: OpenSSL has made a record of them and sends it first.
  PHP turns on `SSL_MODE_ACCEPT_MOVING_WRITE_BUFFER` and `SSL_MODE_ENABLE_PARTIAL_WRITE`, so a retry
  can use another buffer or a shorter chunk that holds the record.
- An `fwrite()` that returns 0 *with* an error has failed: OpenSSL reports a broken pipe this way, not as `false`,
  and waiting for writability then would spin forever.
- `read()`, `write()` and `accept()` call `Pacer::pace()` (one `Pacer(16)` per socket or server) before they touch
  the stream: it yields for a tick after 16 calls in one tick, so a peer that never pauses, one that reads faster
  than we write (another process on the same host), or a flood of connections does not starve the event loop.
  It yields *before* the operation, so a cancelled read has taken nothing and a cancelled write has sent nothing.
- A second `enableTls()` during a handshake is a `LogicException` (`$handshaking`): without it the second one
  waits for good and its records mix with those of the first.
- The `reading` flag is held for the whole `read()`. An overlapping read throws `ConcurrentRead` even when bytes
  have arrived for the pending read. Otherwise an overlapping read could take those bytes and leave the pending
  read waiting.
- One `fread()` asks for at most 1 MiB (`MAX_READ`): PHP allocates the whole length before reading, so a length
  from a header would cost its size in memory before a byte has arrived.
- `Server::accept()` never spins: a failed accept after a readiness wake-up backs off (5 ms doubling to 1 s),
  in a wait that `close()` ends.
- `Server::awaitClose()` wakes on close even when nobody is accepting. A server can stop listening while all
  its connection permits are in use.
- A host name never reaches `stream_socket_client()`/`stream_socket_server()`: PHP resolves it with a blocking
  `getaddrinfo()`. `connect()` resolves through a `Resolver`, `listen()` takes IP addresses only.

## Decisions

- PHP streams, not ext-sockets: TLS exists only for streams. ext-sockets is optional, only to read `SO_ERROR`
  of a failed connect (`Connector::socketError()`); a plain read reset has no message since PHP raises none.
- A DNS stub resolver of our own instead of `gethostbyname()`/`dns_get_record()` (they block the event loop).
  It lives here, not in a `dns` package: it needs sockets, and sockets need it. A UDP socket per query (random port).
  Owner names of answers are not checked (CNAME chains are trusted to the recursive server), so compression pointers
  are only skipped, never followed. A response counts only if its id *and* its question match the query
  (case-insensitively: DNS 0x20); a stray datagram is skipped and the wait goes on.
- DNS caches no negative answers (NXDOMAIN, no records), although RFC 2308 allows it: in Docker and Kubernetes
  a service registers after its clients start, and a cached "not found" would hide it for minutes.
  Each positive answer expires from when it arrived, capped at a day. The combined A and AAAA entry takes
  the earliest expiry because one query may still be waiting while the other answer ages.
  An absolute name (`db.`) is cached under its trailing dot:
  it skips the search domains, so `db` and `db.` may resolve to different hosts.
- DNS follows glibc: SERVFAIL and REFUSED move to the next server instead of failing the lookup; a name with
  at least `ndots` dots is asked as is first, then with the search domains, a shorter one the other way round.
- Rejected for now: sharing one query between concurrent lookups of a host. If it is added, the query must run
  in a scope of its own, not of the first caller: otherwise cancelling that caller fails every other one.
- Internationalized names go through `Internal\asciiHost()` (UTS #46, ext-intl) in both the resolver and `connect()`:
  DNS and certificates know only the ASCII form, so the TLS peer name must be that form too.
- `connect()` tries IPv6 first and has no connect timeout of its own: Happy Eyeballs makes a dead family cost 250 ms,
  not a timeout per address, and the caller bounds the whole call with `timeout()`.
- `listen()` leaves `IPV6_V6ONLY` to the OS: `::` is dual-stack on Linux by default, which is what a server wants.
  `noDelay` is on by default, and the backlog is 511, as in nginx.
- `InetAddress::fromString()` requires brackets around IPv6 so the address and port are distinct. The internal parser
  splits PHP stream names at the last colon so it can also read unbracketed IPv6 names returned by PHP.
- Happy Eyeballs is a group of attempts reporting to a `Channel` sized for all of them, so no attempt waits to report;
  sockets that connect in the same tick as the winner are drained and closed in `finally`.
- A value of a protocol or a file keeps its unit in the internal object that mirrors it: the TTL of
  `Internal\Dns\Response` and `DnsResolver::MAX_TTL` are seconds as on the wire, the timeout of
  `Internal\Dns\SystemConfig` is seconds as in resolv.conf. They become a `Duration` where they leave it
  (`sec($ttl)`); every other time inside is int nanoseconds (`Server` backoff, `Connector::ATTEMPT_DELAY`).
- `Socket` is a final class, not an interface: protocol tests use `socketPair()`, decorators wrap `Reader`/`Writer`.
- `enableTls()` is explicit and `accept()` does no handshake: a slow client must not stall the accept loop,
  and plaintext-first protocols need the upgrade anyway.
- TLS context options that are the defaults of PHP are not set: compression is off (`disable_compression`),
  a server picks the cipher in its own order (`honor_cipher_order`).
- `Certificate::$contextOptions` always has `local_pk`, the certificate file if the key is in it:
  a certificate of `SNI_server_certs` without one fails every handshake that picks it.
- Rejected: `ALPN` together with SNI certificates is not refused at construction: PHP loses ALPN only on connections
  that pick an SNI certificate, which degrades to the default protocol instead of failing (documented in README).

## Tests

- Real sockets over loopback, real time: keep delays at 10–50 ms. `tests/FakeDns.php` is a name server
  (UDP and TCP on one port) answering from `Zone`s, run in daemons of the test's group.
- A port is taken with `listen(..., 0)` in the protocol and on the address it is used on, never found free
  elsewhere and reused: a port the OS picked for UDP or for another address may be held on this one by a TCP
  connection of another test (the same ephemeral range), and the listen fails with "Address in use" now and then.
  `FakeDns` listens on TCP first and binds UDP to its port, trying another port if a UDP socket holds it.
- A connection that hangs is a listener with `backlog: 1` and a full accept queue: Linux drops the SYNs.
- Certificates are generated per process by `certificate()` in `tests/helpers.php`: self-signed CAs valid for
  their name, `localhost` and `127.0.0.1`.
- Keep both ends of a `socketPair()` or `tcpPair()` in variables: an end that goes out of scope closes,
  and the other sees EOF.
- ClientTls and ServerTls cannot stop at TLS 1.2, so the tests of TLS 1.2 (ciphers, the cipher order, the version
  methods) talk to a peer of plain PHP streams in `TlsTest`, polled with `delay()`: a blocking accept or handshake
  would stop the event loop and prevent the async peer from running.
- A stale error is planted with `@trigger_error('unrelated', E_USER_WARNING)` right before the call whose
  `error_clear_last()` it tests: a later suspension lets other fibers replace it.
- A test of leaked sockets counts `get_resources('stream')` with `gc_disable()`: the cycle collector frees
  a forgotten socket some time later, and whether it has run decides the count.
- Timing tests against the backoff of `Server::accept()` (5 ms doubling) connect in the middle of a long gap
  between its polls: the start of an accept shifts them by tens of milliseconds.
