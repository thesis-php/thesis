# Thesis Net

Non-blocking TCP and Unix sockets for [thesis/async](https://github.com/thesis-php/async): TLS, an async DNS resolver,
Happy Eyeballs. The transport of protocol clients and servers (HTTP, Postgres, AMQP, NATS, ...).

> **Experimental.** The API is unstable.

## Installation

```shell
composer require thesis/net
```

With ext-sockets, a failed connection says why (refused, unreachable).

## Usage

Code that waits runs in the event loop of [thesis/async](https://github.com/thesis-php/async): a program starts it
with `Thesis\Async\group()`, and a wait outside it throws `LogicException`. The examples leave it out.

### Connecting

```php
use Thesis\Io\Buffer;
use function Thesis\Async\timeout;
use function Thesis\Net\connect;
use function Thesis\Time\sec;

$socket = timeout(sec(5), static fn() => connect('rabbitmq', 5672));

$out = new Buffer();
$out->write("AMQP\x00\x00\x09\x01")->flush($socket);

$in = new Buffer();
$in->fill($socket, 7);
```

`Socket` is a `Reader`, a `Writer` and a `Closer` of [thesis/io](https://github.com/thesis-php/io): a protocol encodes into a `Buffer`
and flushes it, and fills a `Buffer` from the socket to decode.

There is no timeout parameter anywhere. Every wait is a wait of thesis/async: it is cancelled with its scope,
so `timeout()` bounds a connect, a handshake or a read like anything else, and a cancelled group stops its connections.

A host name is resolved without blocking the event loop (see [DNS](#dns)). If it has several addresses, they are tried
as [RFC 8305](https://www.rfc-editor.org/rfc/rfc8305) recommends: IPv6 and IPv4 alternate, the next attempt starts
once the previous one fails or after 250 ms, and the first to connect wins. A host without IPv6 connectivity
costs nothing, one that drops packets costs 250 ms instead of a minute.

Nagle's algorithm is off (`noDelay: true`): writes are batched in a `Buffer` anyway,
and Nagle would hold back the last packet of every request.

`connectUnix('/run/postgresql/.s.PGSQL.5432')` connects to a Unix socket.

### Reading and writing

- `read(int $max)` returns the bytes that have arrived, 1 to `$max`, and waits only if there are none.
  It throws `UnexpectedEof` once the peer has closed its side and everything is read, `ReadFailed` if the connection is
  broken (reset, TLS error), `SocketClosed` if this side has closed it. A cancelled read has taken nothing.
  A peer that sends without a pause does not starve the process: after a few reads that found bytes at once,
  `read()` lets the other tasks run for a tick.
- `write(string $bytes)` returns once every byte is handed to the OS. Any number of fibers may write: writes go
  one after another, never interleaved, so a frame written by one call stays whole.
  A write cancelled before it has sent anything leaves the socket as it was; a write cancelled or failed half-way
  **closes the socket**, because the peer has got half a message and nothing after it would make sense.
- One fiber reads at a time: a second reader gets a `LogicException`. A protocol has one reader loop per connection.
- `close()` closes at once; reads and writes waiting in other fibers throw `SocketClosed`.
  `closeWrite()` sends the end of the stream and keeps reading (not over TLS: PHP cannot do it).

### TLS

```php
use Thesis\Net\ClientTls;

$socket = connect('rabbitmq', 5671, tls: new ClientTls());
```

`ClientTls` verifies the certificate against the system CA store and the host passed to `connect()`.
`caFile` trusts a private CA, `certificate` is a client certificate, `alpnProtocols` negotiates a protocol:

```php
use Thesis\Net\Certificate;

$tls = new ClientTls(
    caFile: '/etc/ssl/internal-ca.pem',
    certificate: new Certificate(certFile: '/etc/app/client.pem', keyFile: '/etc/app/client.key'),
    alpnProtocols: ['h2', 'http/1.1'],
);

$socket = connect('api.internal', 443, tls: $tls);
$socket->tls?->alpnProtocol; // 'h2'
```

Protocols that start in plaintext and switch (Postgres `SSLRequest`, STARTTLS, NATS) call `enableTls()` themselves:

```php
$socket = connect($host, 5432);
$socket->write(pack('NN', 8, 80877103)); // SSLRequest

if ($socket->read(1) === 'S') {
    $socket->enableTls(new ClientTls());
}
```

A handshake that fails or is cancelled closes the socket. Nothing else may read or write during it.

### Servers

```php
use Thesis\Async\Group;
use Thesis\Net\Certificate;
use Thesis\Net\ServerTls;
use Thesis\Net\Socket;
use function Thesis\Async\group;
use function Thesis\Async\timeout;
use function Thesis\Net\listen;
use function Thesis\Time\sec;

$server = listen('0.0.0.0', 8443);
$tls = new ServerTls(
    certificate: new Certificate(certFile: '/etc/app/server.pem'),
    alpnProtocols: ['h2', 'http/1.1'],
);

try {
    group(static function (Group $group) use ($server, $tls) {
        while (null !== $socket = $server->accept()) {
            $group->spawn(static function () use ($socket, $tls) {
                try {
                    timeout(sec(10), static fn() => $socket->enableTls($tls));
                    handle($socket); // catches its own errors: a failure would fail the whole group
                } finally {
                    $socket->close();
                }
            });
        }
    });
} finally {
    $server->close();
}
```

`accept()` returns `null` once the server is closed, a wait in progress included, so the loop ends by itself;
cancelling the group stops it as well. It performs no handshake: one slow client must not hold up the others.
A process out of file descriptors does not spin: `accept()` backs off for up to a second and tries again.
A flood of connections does not starve the connections already accepted: `accept()` yields now and then too.

To limit the connections, take a `LocalSemaphore` before `accept()` and release it when the task of the connection
ends.

`listen('::', ...)` accepts IPv4 too, unless the OS is configured otherwise (`net.ipv6.bindv6only` on Linux).

`listen()` takes an IP address: `'0.0.0.0'`, `'::'` (every interface, IPv4 too on most systems), `'127.0.0.1'`.
Port `0` picks a free one, `$server->address` tells which. `reusePort: true` lets several processes share a port.
`listenUnix($path)` listens on a Unix socket and removes the file on `close()`.

`ServerTls` takes certificates per host name (`sniCertificates`) and requires client certificates with `clientCaFile`;
the client's certificate is then in `$socket->tls->peerCertificate`.

### DNS

`DnsResolver::system()` is the default resolver of `connect()`: a stub resolver like the one of glibc, configured by
`/etc/resolv.conf` (`nameserver`, `search`, `ndots`, `timeout`, `attempts`) and `/etc/hosts`. It asks for AAAA and A
at once, over UDP and over TCP if the answer does not fit, tries the next server if one fails or is silent, and caches
answers for their TTL, a day at most. `localhost` is always loopback. `gethostbyname()` would block the whole process
meanwhile.

A name that does not exist is asked for again every time, never cached: in Docker or Kubernetes a service registers
its name seconds after its clients start, and they must see it at once.

An internationalized name (`Пример.test`) is asked for in its ASCII form (`xn--e1afmkfd.test`, UTS #46), and
`connect()` verifies the TLS certificate against that form. This needs ext-intl; without it, such a name fails
with `DnsFailed`.

Another resolver implements `Resolver` (service discovery, a fixed map in tests) and is passed to `connect()`:

```php
$socket = connect('db', 5432, resolver: DnsResolver::fromFiles('/etc/app/resolv.conf'));
```

### Tests

`socketPair()` returns two connected sockets in the process: a protocol client is tested against a fake server
in the same event loop, without a network.

## API

| | |
|---|---|
| `connect(string $host, int $port, ?ClientTls $tls, bool $noDelay = true, ?Resolver $resolver): Socket` | TCP, Happy Eyeballs, TLS |
| `connectUnix(string $path): Socket` | Unix socket |
| `listen(string $host, int $port, int $backlog = 511, bool $reusePort = false, bool $noDelay = true): Server` | TCP server on an IP address |
| `listenUnix(string $path, int $backlog = 511): Server` | Unix server |
| `socketPair(): array{Socket, Socket}` | two connected sockets |
| `Socket`: `read()`, `write()`, `closeWrite()`, `enableTls()`, `close()`, `$tls`, `$localAddress`, `$remoteAddress`, `$isClosed` | a connection |
| `Server`: `accept(): ?Socket`, `close()`, `$address`, `$isClosed` | a listening socket |
| `ClientTls`, `ServerTls`, `Certificate`, `TlsVersion`, `TlsInfo` | TLS |
| `Resolver`, `DnsResolver`: `system()`, `fromFiles()` | DNS |
| `Address`: `InetAddress`, `UnixAddress` | ends of a socket |
| `ConnectFailed`, `ListenFailed`, `TlsFailed`, `DnsFailed`, `SocketClosed` extend `Thesis\Io\IoException` | exceptions |

## Limitations

- ALPN is not negotiated on a connection that gets one of `ServerTls::$sniCertificates`: PHP loses it when it switches
  the certificate, so such a client falls back to its default (HTTP/1.1 for HTTP). Use one certificate for several names.
- A TLS socket cannot be half-closed.
- A reset of a plain TCP connection is reported without the OS error: PHP raises none for a non-blocking read.
- `TlsInfo` holds the certificate of the peer, not its chain.

## TODO

- UDP sockets (DNS uses its own internally).
- TCP keepalive options: PHP has no socket context option for them, they need ext-sockets.
- Concurrent lookups of the same host share one query.
- `TlsInfo::$peerCertificateChain`, for pinning an intermediate CA.
- A pool of connections, for the protocol clients.
