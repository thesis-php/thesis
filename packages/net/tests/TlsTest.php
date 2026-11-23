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
use function Thesis\Async\timeoutOr;
use function Thesis\Async\Trace\watchdog;
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
        Assert::same($clientSocket->tls->cipher, 'TLS_AES_256_GCM_SHA384');
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

        $failure = thrown(static fn() => timeout(ms(50), static fn() => $client->enableTls($tls)));

        Assert::instanceOf($failure, TimedOut::class);
        Assert::true($client->isClosed);
        Assert::false($server->isClosed);
    }

    public function cannotBeEnabledWhileAnotherHandshakeIsInProgress(): void
    {
        [$client, $server] = tcpPair();
        $clientTls = self::trusting('server.test', peerName: 'server.test');

        group(static function (Group $group) use ($client, $server, $clientTls) {
            $first = $group->spawn(static fn() => $client->enableTls($clientTls));
            delay(ms(10));

            // Without the check the second handshake waits for good.
            $failure = thrown(static fn() => timeout(sec(1), static fn() => $client->enableTls($clientTls)));

            Assert::instanceOf($failure, \LogicException::class);
            Assert::same($failure->getMessage(), 'TLS is already being enabled.');
            Assert::false($client->isClosed);
            $server->enableTls(self::server('server.test'));
            $first->await();
        });

        Assert::notNull($client->tls);
    }

    public function cannotBeEnabledWhileAnotherFiberReads(): void
    {
        // the server end is kept: closed, it would end the read and the write
        [$client, $server] = tcpPair();
        Expect
            ::exception(\LogicException::class)
            ->withMessage('TLS cannot be enabled while another fiber reads or writes.')
        ;

        group(static function (Group $group) use ($client) {
            $group->spawn(static fn() => $client->read(1));
            delay(ms(1));
            $client->enableTls(self::trusting('server.test', peerName: 'server.test'));
        });
    }

    public function cannotBeEnabledWhileAnotherFiberWrites(): void
    {
        // the server end is kept: closed, it would end the read and the write
        [$client, $server] = tcpPair();
        Expect
            ::exception(\LogicException::class)
            ->withMessage('TLS cannot be enabled while another fiber reads or writes.')
        ;

        group(static function (Group $group) use ($client) {
            $group->spawn(static fn() => $client->write(str_repeat('x', 10_000_000)));
            delay(ms(10));
            $client->enableTls(self::trusting('server.test', peerName: 'server.test'));
        });
    }

    public function isEnabledOnceTheWritesThatWaitedAreDone(): void
    {
        [$client, $server] = tcpPair();

        group(static function (Group $group) use ($client, $server) {
            $group->spawn(static fn() => $client->write(str_repeat('a', 10_000_000)));
            delay(ms(10));
            // cancelled while it waits for the write above
            timeoutOr(ms(10), static fn() => $client->write('b'));
            readExactly($server, 10_000_000);
        });
        all([
            static fn() => $client->enableTls(self::trusting('server.test', peerName: 'server.test')),
            static fn() => $server->enableTls(self::server('server.test')),
        ]);

        Assert::same($client->tls?->protocol, 'TLSv1.3');
    }

    public function localCloseAfterReadinessEndsTheHandshake(): void
    {
        [$client, $peer] = tcpPair();
        timeout(sec(1), static fn() => group(static function (Group $group) use ($client, $peer) {
            $pending = $group->spawn(static fn() => thrown(static fn() => $client->enableTls(new ClientTls())));
            $group->spawn(static function () use ($client, $peer) {
                $peer->close();
                // The poll wakes the handshake. Close locally before its fiber resumes.
                watchdog(sec(0), $client->close(...));
            });

            Assert::instanceOf($pending->await(), SocketClosed::class);
        }));
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

    public function failsWithoutAnUnrelatedErrorIfThePeerHasClosedBefore(): void
    {
        [$client, $server] = tcpPair();
        $server->close();
        delay(ms(10));
        Expect::exception(TlsFailed::class)->withMessage('TLS handshake failed: the peer has closed the connection.');
        @trigger_error('unrelated', E_USER_WARNING);

        $client->enableTls(new ClientTls(peerName: 'server.test'));
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

    public function connectsWithoutVerifyingThePeer(): void
    {
        [$client] = self::handshake(self::server('server.test'), new ClientTls(verifyPeer: false));

        Assert::same(self::commonName($client->tls?->peerCertificate), 'server.test');
    }

    public function connectsWithoutVerifyingThePeerName(): void
    {
        $client = new ClientTls(
            peerName: 'other.test',
            verifyPeerName: false,
            caFile: certificate('server.test')->certFile,
        );

        [$clientSocket] = self::handshake(self::server('server.test'), $client);

        Assert::same(self::commonName($clientSocket->tls?->peerCertificate), 'server.test');
    }

    public function trustsTheCasOfADirectory(): void
    {
        $certFile = certificate('server.test')->certFile;
        $hash = openssl_x509_parse((string) file_get_contents($certFile))['hash'] ?? null;
        \assert(\is_string($hash));
        // OpenSSL looks up the hashed names only, so the other files of the directory are no CAs.
        $caPath = \dirname($certFile);
        copy($certFile, "{$caPath}/{$hash}.0");

        [$client] = self::handshake(self::server('server.test'), new ClientTls(caPath: $caPath));

        Assert::same(self::commonName($client->tls?->peerCertificate), 'server.test');
    }

    public function takesTheKeyFromTheCertificateFile(): void
    {
        [$client] = self::handshake(
            serverTls: new ServerTls(self::withKeyInside('server.test')),
            clientTls: self::trusting('server.test'),
        );

        Assert::same(self::commonName($client->tls?->peerCertificate), 'server.test');
    }

    public function takesTheKeyFromTheFileOfAServerNameCertificate(): void
    {
        $server = new ServerTls(
            certificate: certificate('a.test'),
            sniCertificates: ['b.test' => self::withKeyInside('b.test')],
        );
        $client = new ClientTls(peerName: 'b.test', caFile: certificate('b.test')->certFile);

        [$clientSocket] = self::handshake($server, $client);

        Assert::same(self::commonName($clientSocket->tls?->peerCertificate), 'b.test');
    }

    public function decryptsTheKeyWithThePassphrase(): void
    {
        $certificate = certificate('server.test');
        \assert($certificate->keyFile !== null);
        $key = openssl_pkey_get_private((string) file_get_contents($certificate->keyFile));
        \assert($key !== false);
        $keyFile = \dirname($certificate->certFile) . '/server.test-encrypted.key';
        openssl_pkey_export_to_file($key, $keyFile, passphrase: 'secret');

        [$client] = self::handshake(
            serverTls: new ServerTls(new Certificate($certificate->certFile, $keyFile, passphrase: 'secret')),
            clientTls: self::trusting('server.test'),
        );

        Assert::same(self::commonName($client->tls?->peerCertificate), 'server.test');
    }

    public function connectsToATls12ServerWithTheCiphersGiven(): void
    {
        // not the first of the default ciphers of PHP
        $clientTls = new ClientTls(
            caFile: certificate('server.test')->certFile,
            ciphers: 'ECDHE-ECDSA-CHACHA20-POLY1305',
        );

        $client = self::connectToTls12($clientTls);

        Assert::same($client->tls?->protocol, 'TLSv1.2');
        Assert::same($client->tls->cipher, 'ECDHE-ECDSA-CHACHA20-POLY1305');
    }

    public function refusesATls12ServerIfTls13IsRequired(): void
    {
        $clientTls = new ClientTls(caFile: certificate('server.test')->certFile, minVersion: TlsVersion::Tls13);
        Expect::exception(TlsFailed::class);

        self::connectToTls12($clientTls);
    }

    public function acceptsATls12ClientInTheOrderOfItsCiphers(): void
    {
        $serverTls = new ServerTls(
            certificate: certificate('server.test'),
            ciphers: 'ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-ECDSA-AES128-GCM-SHA256',
        );

        $server = self::acceptTls12($serverTls);

        Assert::same($server->tls?->protocol, 'TLSv1.2');
        Assert::same($server->tls->cipher, 'ECDHE-ECDSA-CHACHA20-POLY1305');
    }

    public function refusesATls12ClientIfTls13IsRequired(): void
    {
        $serverTls = new ServerTls(certificate: certificate('server.test'), minVersion: TlsVersion::Tls13);
        Expect::exception(TlsFailed::class);

        self::acceptTls12($serverTls);
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

    /**
     * @param non-empty-string $name
     */
    private static function withKeyInside(string $name): Certificate
    {
        $certificate = certificate($name);
        \assert($certificate->keyFile !== null);
        $certFile = \dirname($certificate->certFile) . "/{$name}-with-key.pem";
        file_put_contents(
            filename: $certFile,
            data: file_get_contents($certificate->certFile) . file_get_contents($certificate->keyFile),
        );

        return new Certificate($certFile);
    }

    /**
     * Connects to a server of plain PHP streams that speaks TLS 1.2 only: ServerTls cannot stop at 1.2.
     */
    private static function connectToTls12(ClientTls $clientTls): Socket
    {
        $certificate = certificate('server.test');
        $server = stream_socket_server(
            address: 'tcp://127.0.0.1:0',
            context: stream_context_create([
                'ssl' => ['local_cert' => $certificate->certFile, 'local_pk' => $certificate->keyFile],
            ]),
        );
        \assert($server !== false);
        $address = (string) stream_socket_get_name($server, remote: false);
        $port = (int) substr($address, (int) strrpos($address, ':') + 1);
        \assert($port > 0 && $port <= 65535);

        try {
            return group(static function (Group $group) use ($server, $port, $clientTls) {
                $group->spawn(static function () use ($server) {
                    // polled: a wait in stream_socket_accept() would block the event loop, the client too
                    while (true) {
                        $peer = @stream_socket_accept($server, timeout: 0);

                        if ($peer !== false) {
                            break;
                        }

                        delay(ms(1));
                    }

                    self::rawHandshake($peer, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER);

                    return $peer;
                });

                return connect('127.0.0.1', $port, tls: $clientTls, resolver: new StaticResolver(['127.0.0.1']));
            });
        } finally {
            fclose($server);
        }
    }

    /**
     * Accepts a client of plain PHP streams that speaks TLS 1.2 only and offers its ciphers in another order
     * than the server prefers: ClientTls cannot stop at 1.2.
     */
    private static function acceptTls12(ServerTls $serverTls): Socket
    {
        $server = listen('127.0.0.1', 0);
        \assert($server->address instanceof InetAddress);
        $address = 'tcp://' . $server->address;

        try {
            return group(static function (Group $group) use ($server, $serverTls, $address) {
                $accepted = $group->spawn(static function () use ($server, $serverTls) {
                    $socket = $server->accept();
                    \assert($socket !== null);
                    $socket->enableTls($serverTls);

                    return $socket;
                });

                $client = stream_socket_client(
                    address: $address,
                    context: stream_context_create([
                        'ssl' => [
                            'verify_peer' => false,
                            'ciphers' => 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-ECDSA-CHACHA20-POLY1305'
                                . ':ECDHE-ECDSA-AES256-GCM-SHA384',
                        ],
                    ]),
                );
                \assert($client !== false);
                $group->spawn(static fn() => self::rawHandshake($client, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT));

                return $accepted->await();
            });
        } finally {
            $server->close();
        }
    }

    /**
     * @param resource $stream
     */
    private static function rawHandshake(mixed $stream, int $method): void
    {
        stream_set_blocking($stream, false);

        while (@stream_socket_enable_crypto($stream, true, $method) === 0) {
            delay(ms(1));
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
