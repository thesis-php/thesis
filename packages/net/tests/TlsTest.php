<?php

declare(strict_types=1);

namespace Thesis\Net;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Group;
use Thesis\Async\TimedOut;
use Thesis\Io\WriteFailed;
use function Thesis\Async\all;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\timeout;
use function Thesis\Time\ms;
use function Thesis\Time\sec;

#[Test]
final class TlsTest
{
    public function negotiatesTheProtocolAndAlpn(): void
    {
        $server = new ServerTls(certificate: certificate('server.test'), alpnProtocols: ['h2', 'http/1.1']);
        $client = new ClientTls(caFile: certificate('server.test')->certFile, alpnProtocols: ['h2']);

        [$clientSocket, $serverSocket] = self::handshake($server, $client);

        Assert::same($clientSocket->tls?->protocol, 'TLSv1.3');
        Assert::same($clientSocket->tls->alpnProtocol, 'h2');
        Assert::same($serverSocket->tls?->alpnProtocol, 'h2');
        Assert::same(self::commonName($clientSocket->tls->peerCertificate), 'server.test');
        Assert::null($serverSocket->tls->peerCertificate);
    }

    public function verifiesTheHostPassedToConnect(): void
    {
        [$client, $server] = self::handshake(
            serverTls: self::server('server.test'),
            clientTls: self::trusting('server.test'),
            host: 'localhost',
        );

        Assert::same($client->tls?->protocol, $server->tls?->protocol);
    }

    public function transfersLargePayloadsBothWaysAtOnce(): void
    {
        [$client, $server] = self::handshake(self::server('server.test'), self::trusting('server.test'));
        $up = random_bytes(8_000_000);
        $down = random_bytes(8_000_000);

        [$receivedUp, $receivedDown] = exchange($client, $server, $up, $down);

        Assert::true($receivedUp === $up);
        Assert::true($receivedDown === $down);
    }

    public function throwsWriteFailedOnceThePeerIsGone(): void
    {
        [$client, $server] = self::handshake(self::server('server.test'), self::trusting('server.test'));
        $server->close();
        delay(ms(10));
        Expect::exception(WriteFailed::class)->withMessageContaining('Broken pipe');

        // OpenSSL reports a broken pipe as a write of 0 bytes with a warning, not as false
        timeout(sec(1), static function () use ($client) {
            $client->write('x');
            delay(ms(10));
            $client->write('x');
        });
    }

    public function verifiesAnInternationalizedHostAsItsAsciiForm(): void
    {
        $server = new ServerTls(certificate: certificate('xn--e1afmkfd.test'));
        $client = new ClientTls(caFile: certificate('xn--e1afmkfd.test')->certFile);

        [$clientSocket] = self::handshake($server, $client, host: 'Пример.test');

        Assert::same(self::commonName($clientSocket->tls?->peerCertificate), 'xn--e1afmkfd.test');
    }

    public function rejectsAnUntrustedCertificate(): void
    {
        Expect::exception(TlsFailed::class)->withMessageContaining('certificate verify failed');

        self::handshake(self::server('server.test'), new ClientTls());
    }

    public function rejectsACertificateForAnotherName(): void
    {
        $client = new ClientTls(peerName: 'other.test', caFile: certificate('server.test')->certFile);
        Expect::exception(TlsFailed::class)->withMessageContaining("did not match expected name `other.test'");

        self::handshake(self::server('server.test'), $client);
    }

    public function picksTheCertificateByServerName(): void
    {
        $server = new ServerTls(
            certificate: certificate('a.test'),
            sniCertificates: ['b.test' => certificate('b.test')],
        );
        $client = new ClientTls(peerName: 'b.test', caFile: certificate('b.test')->certFile);

        [$clientSocket] = self::handshake($server, $client);

        Assert::same(self::commonName($clientSocket->tls?->peerCertificate), 'b.test');
    }

    public function requiresAClientCertificate(): void
    {
        $client = new ClientTls(caFile: certificate('server.test')->certFile, certificate: certificate('client.test'));

        [, $serverSocket] = self::handshake(self::requiringClientCertificate(), $client);

        Assert::same(self::commonName($serverSocket->tls?->peerCertificate), 'client.test');
    }

    public function rejectsAClientWithoutACertificate(): void
    {
        [$client, $server] = tcpPair();
        $clientTls = self::trusting('server.test', peerName: 'server.test');
        Expect::exception(TlsFailed::class)->withMessage('TLS handshake failed: Could not get peer certificate');

        // In TLS 1.3 the client is done before the server has checked it: the server is the one that fails.
        all([
            static fn() => $client->enableTls($clientTls),
            static fn() => $server->enableTls(self::requiringClientCertificate()),
        ]);
    }

    public function upgradesAPlaintextConnection(): void
    {
        [$client, $server] = tcpPair();
        $clientTls = self::trusting('server.test', peerName: 'server.test');

        $reply = group(static function (Group $group) use ($client, $server, $clientTls) {
            $group->spawn(static function () use ($client, $clientTls) {
                $client->write("STARTTLS\n");
                Assert::same(readExactly($client, 3), "OK\n");
                $client->enableTls($clientTls);
                $client->write('secret');
            });

            Assert::same(readExactly($server, 9), "STARTTLS\n");
            $server->write("OK\n");
            $server->enableTls(self::server('server.test'));

            return readExactly($server, 6);
        });

        Assert::same($reply, 'secret');
        Assert::notNull($client->tls);
    }

    public function closesTheSocketIfTheHandshakeIsCancelled(): void
    {
        [$client, $server] = tcpPair();
        $tls = new ClientTls(peerName: 'server.test');

        try {
            timeout(ms(50), static fn() => $client->enableTls($tls));
            Assert::fail('Expected TimedOut.');
        } catch (TimedOut) {
        }

        Assert::true($client->isClosed);
        Assert::false($server->isClosed);
    }

    public function failsIfThePeerClosesDuringTheHandshake(): void
    {
        [$client, $server] = tcpPair();
        Expect::exception(TlsFailed::class);

        group(static function (Group $group) use ($client, $server) {
            $group->spawn(static function () use ($server) {
                delay(ms(10));
                $server->close();
            });
            $client->enableTls(new ClientTls(peerName: 'server.test'));
        });
    }

    public function cannotBeEnabledTwice(): void
    {
        [$client] = self::handshake(self::server('server.test'), self::trusting('server.test'));
        Expect::exception(\LogicException::class)->withMessage('TLS is already enabled.');

        $client->enableTls(new ClientTls());
    }

    public function cannotBeHalfClosed(): void
    {
        [$client] = self::handshake(self::server('server.test'), self::trusting('server.test'));
        Expect::exception(\LogicException::class)->withMessage('A TLS socket cannot be half-closed.');

        $client->closeWrite();
    }

    public function needsAPeerNameWithoutAHost(): void
    {
        [$left] = socketPair();
        Expect
            ::exception(\LogicException::class)
            ->withMessage('The socket was not connected to a host name: pass ClientTls::$peerName.')
        ;

        $left->enableTls(new ClientTls());
    }

    /**
     * @param non-empty-string $name
     */
    private static function server(string $name): ServerTls
    {
        return new ServerTls(certificate: certificate($name));
    }

    /**
     * @param non-empty-string $name
     * @param ?non-empty-string $peerName
     */
    private static function trusting(string $name, ?string $peerName = null): ClientTls
    {
        return new ClientTls(peerName: $peerName, caFile: certificate($name)->certFile);
    }

    private static function requiringClientCertificate(): ServerTls
    {
        return new ServerTls(
            certificate: certificate('server.test'),
            clientCaFile: certificate('client.test')->certFile,
        );
    }

    /**
     * Connects to $host (always 127.0.0.1), where a server does its side of the handshake.
     * A failure of the server side is ignored: the client reports its own.
     *
     * @param non-empty-string $host
     * @return array{Socket, Socket} the client end first
     */
    private static function handshake(ServerTls $serverTls, ClientTls $clientTls, string $host = '127.0.0.1'): array
    {
        $server = listen('127.0.0.1', 0);
        \assert($server->address instanceof InetAddress);
        $port = $server->address->port;

        try {
            return group(static function (Group $group) use ($server, $serverTls, $clientTls, $host, $port) {
                $accepted = $group->spawn(static function () use ($server, $serverTls) {
                    $socket = $server->accept();
                    \assert($socket !== null);

                    try {
                        $socket->enableTls($serverTls);
                    } catch (TlsFailed) {
                    }

                    return $socket;
                });

                $client = connect($host, $port, tls: $clientTls, resolver: new StaticResolver(['127.0.0.1']));

                return [$client, $accepted->await()];
            });
        } finally {
            $server->close();
        }
    }

    private static function commonName(?\OpenSSLCertificate $certificate): ?string
    {
        if ($certificate === null) {
            return null;
        }

        $subject = openssl_x509_parse($certificate)['subject'] ?? null;
        \assert(\is_array($subject) && \is_string($subject['CN'] ?? null));

        return $subject['CN'];
    }
}
