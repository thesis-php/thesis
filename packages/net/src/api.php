<?php

declare(strict_types=1);

namespace Thesis\Net;

use Thesis\Async\Cancelled;
use Thesis\Net\Internal\Connector;
use function Thesis\Net\Internal\lastError;

/**
 * Connects over TCP. A host name is resolved by $resolver without blocking the event loop.
 * If it has several addresses, they are tried as RFC 8305 (Happy Eyeballs) recommends: alternating IPv6 and IPv4,
 * the next attempt starts once the previous one fails or after 250 ms, and the first to connect wins.
 *
 * There is no connect timeout: bound the call with `timeout()`, the TLS handshake included.
 * Without one, the OS gives up on an unreachable host in a minute or two.
 *
 * @api
 * @param non-empty-string $host a host name or an IP address, IPv6 with or without brackets (a URL has them)
 * @param int<0, 65535> $port
 * @param ?ClientTls $tls if set, the handshake is done before returning; $host is the default peer name,
 *     an internationalized one in its ASCII form
 * @param bool $noDelay turns Nagle's algorithm off: a protocol batches its writes in a Buffer anyway,
 *     and Nagle would hold back the last small packet of every request
 * @param ?Resolver $resolver {@see DnsResolver::system()} if null
 * @throws ConnectFailed
 * @throws TlsFailed
 * @throws Cancelled if cancellation of the scope interrupts the connection attempt
 */
function connect(
    string $host,
    int $port,
    ?ClientTls $tls = null,
    bool $noDelay = true,
    ?Resolver $resolver = null,
): Socket {
    $ip = Internal\ip($host);

    try {
        if ($ip !== null) {
            $ips = [$ip];
        } else {
            $ips = ($resolver ?? DnsResolver::system())->resolve($host);
            $host = Internal\asciiHost($host) ?? $host;
        }
    } catch (DnsFailed $exception) {
        throw new ConnectFailed(
            message: "Failed to connect to {$host}:{$port}: {$exception->getMessage()}",
            previous: $exception,
        );
    }

    $socket = Connector::tcp($ips, $port, $noDelay, $host);

    if ($tls !== null) {
        $socket->enableTls($tls);
    }

    return $socket;
}

/**
 * Connects to a Unix socket.
 *
 * @api
 * @param non-empty-string $path
 * @throws ConnectFailed
 */
function connectUnix(string $path): Socket
{
    return Connector::unix($path);
}

/**
 * Listens on a TCP port. Accepted sockets have Nagle's algorithm turned off unless $noDelay is false.
 *
 * @api
 * @param non-empty-string $host Pass '0.0.0.0' for all IPv4 interfaces, '::' or '[::]' for all interfaces
 *     (including IPv4 unless the OS disables it), or '127.0.0.1' for local clients only.
 *     The function accepts an IP address so binding does not depend on DNS.
 * @param int<0, 65535> $port Pass 0 to let the OS pick a free port. See {@see Server::$address}.
 * @param positive-int $backlog connections the OS keeps until they are accepted, capped by the OS (somaxconn)
 * @param bool $reusePort lets several processes listen on the same port, the OS spreads the connections (SO_REUSEPORT)
 * @throws ListenFailed
 * @throws \ValueError if $host is not an IP address
 */
function listen(
    string $host,
    int $port,
    int $backlog = 511,
    bool $reusePort = false,
    bool $noDelay = true,
): Server {
    $address = new InetAddress(ip: Internal\ip($host) ?? $host, port: $port);

    return Server::create(Internal\listen(
        uri: 'tcp://' . $address,
        options: [
            'backlog' => $backlog,
            'so_reuseport' => $reusePort,
            'tcp_nodelay' => $noDelay,
        ],
    ));
}

/**
 * Listens on a Unix socket. The file must not exist: remove a stale one first.
 * {@see Server::close()} removes it.
 *
 * @api
 * @param non-empty-string $path
 * @param positive-int $backlog
 * @throws ListenFailed
 */
function listenUnix(string $path, int $backlog = 511): Server
{
    return Server::create(
        stream: Internal\listen('unix://' . $path, ['backlog' => $backlog]),
        unixPath: $path,
    );
}

/**
 * Two connected Unix sockets: what is written to one is read from the other.
 * For tests of a protocol, and for talking to a child process.
 *
 * @api
 * @return array{Socket, Socket}
 */
function socketPair(): array
{
    error_clear_last();
    $pair = @stream_socket_pair(domain: STREAM_PF_UNIX, type: STREAM_SOCK_STREAM, protocol: STREAM_IPPROTO_IP);

    if ($pair === false) {
        throw new \RuntimeException(lastError('Failed to create a socket pair.'));
    }

    [$left, $right] = $pair + [null, null];
    \assert($left !== null && $right !== null);

    return [
        Socket::create($left, unix: true),
        Socket::create($right, unix: true),
    ];
}
