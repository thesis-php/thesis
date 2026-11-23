<?php

declare(strict_types=1);

namespace Thesis\HttpServer;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Deferred;
use Thesis\Async\Group;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Status;
use Thesis\Io\Buffer;
use Thesis\Io\StringReader;
use Thesis\Io\WriteFailed;
use Thesis\Net\Certificate;
use Thesis\Net\ClientTls;
use Thesis\Net\InetAddress;
use Thesis\Net\ListenFailed;
use Thesis\Net\ServerTls;
use Thesis\Net\TlsFailed;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\timeout;
use function Thesis\Async\timeoutOr;
use function Thesis\Net\connect;
use function Thesis\Net\listen;
use function Thesis\Time\ms;
use function Thesis\Time\sec;

#[Test]
final class ServerTest
{
    public function aClientThatStopsReadingExpiresAtTheWriteDeadline(): void
    {
        timeout(sec(2), static fn() => group(static function (Group $group) {
            /** @var Deferred<\Throwable> $failure */
            $failure = new Deferred();
            $server = new Server(
                config: new Config(0, idleTimeout: sec(5), writeTimeout: ms(20)),
                handler: static fn() => new Response(body: new StringReader(str_repeat('x', 16 * 1024 * 1024))),
                onError: static function (\Throwable $error) use ($failure) {
                    $failure->complete($error);
                },
            );
            $server->run($group);
            $address = $server->address;
            \assert($address instanceof InetAddress);
            $client = connect('127.0.0.1', $address->port);

            try {
                $client->write("GET / HTTP/1.1\r\nHost: local\r\n\r\n");
                $error = $failure->await();
                Assert::instanceOf($error, WriteFailed::class);
                Assert::same('The response write did not complete in time.', $error->getMessage());
            } finally {
                $client->close();
                $server->close();
            }
        }));
    }

    public function drainsAnActiveResponseWhenTheListenerClosesAtCapacity(): void
    {
        timeout(sec(2), static fn() => group(static function (Group $group) {
            /** @var Deferred<Context> $entered */
            $entered = new Deferred();
            /** @var Deferred<null> $release */
            $release = new Deferred();
            $server = new Server(
                config: new Config('127.0.0.1:0', maxConnections: 1),
                handler: static function (Request $request, Context $context) use ($entered, $release) {
                    $entered->complete($context);
                    $release->await();

                    return new Response(Status::NO_CONTENT);
                },
            );
            $server->run($group);
            $address = $server->address;
            \assert($address instanceof InetAddress);

            $client = connect('127.0.0.1', $address->port);

            try {
                $client->write("GET / HTTP/1.1\r\nHost: local\r\n\r\n");
                $context = $entered->await();
                Assert::same((string) $address, (string) $context->localAddress);
                Assert::same((string) $client->localAddress, (string) $context->remoteAddress);
                Assert::null($context->tls);

                $server->close();
                $release->complete();

                $buffer = new Buffer();
                $buffer->fillUntil($client, "\r\n\r\n");
                Assert::same(
                    "HTTP/1.1 204 No Content\r\nConnection: close",
                    $buffer->readUntil("\r\n\r\n"),
                );
                Assert::null($client->read(1));
            } finally {
                $server->close();
                $client->close();
                $release->complete();
            }
        }));
    }

    public function cancelsAStalledHandlerWhenShutdownExpires(): void
    {
        timeout(sec(2), static fn() => group(static function (Group $group) {
            /** @var Deferred<null> $entered */
            $entered = new Deferred();
            $server = new Server(
                config: new Config(0, shutdownTimeout: ms(10)),
                handler: static function () use ($entered) {
                    $entered->complete();
                    delay(sec(10));

                    return new Response(Status::NO_CONTENT);
                },
            );
            $server->run($group);
            $address = $server->address;
            \assert($address instanceof InetAddress);
            $client = connect('127.0.0.1', $address->port);

            try {
                $client->write("GET / HTTP/1.1\r\nHost: local\r\n\r\n");
                $entered->await();
                $server->close();

                Assert::null($client->read(1));
            } finally {
                $server->close();
                $client->close();
            }
        }));
    }

    public function waitsForCapacityBeforeStartingAnotherConnection(): void
    {
        timeout(sec(2), static fn() => group(static function (Group $group) {
            /** @var Deferred<null> $entered */
            $entered = new Deferred();
            /** @var Deferred<null> $release */
            $release = new Deferred();
            /** @var Deferred<null> $next */
            $next = new Deferred();
            $server = new Server(
                config: new Config(new InetAddress('127.0.0.1', 0), maxConnections: 1),
                handler: static function (Request $request) use ($entered, $release, $next) {
                    if ($request->uri->getRawPath() === '/first') {
                        $entered->complete();
                        $release->await();
                    } else {
                        $next->complete();
                    }

                    return new Response(Status::NO_CONTENT);
                },
            );
            $server->run($group);
            $address = $server->address;
            \assert($address instanceof InetAddress);
            $first = connect('127.0.0.1', $address->port);
            $second = connect('127.0.0.1', $address->port);

            try {
                $first->write("GET /first HTTP/1.1\r\nHost: local\r\nConnection: close\r\n\r\n");
                $entered->await();
                $second->write("GET /next HTTP/1.1\r\nHost: local\r\n\r\n");
                delay(ms(10));
                Assert::false($next->isDone);

                $release->complete();
                $next->await();
            } finally {
                $server->close();
                $first->close();
                $second->close();
                $release->complete();
            }
        }));
    }

    public function observesHandlerFailuresAndAcceptsAnotherConnection(): void
    {
        timeout(sec(2), static fn() => group(static function (Group $group) {
            /** @var Deferred<\Throwable> $failure */
            $failure = new Deferred();
            $error = new \RuntimeException('Handler failed.');
            $server = new Server(
                config: new Config(new InetAddress('127.0.0.1', 0)),
                handler: static function (Request $request) use ($error) {
                    if ($request->uri->getRawPath() === '/fail') {
                        throw $error;
                    }

                    return new Response(Status::NO_CONTENT);
                },
                onError: static function (\Throwable $error) use ($failure) {
                    $failure->complete($error);
                },
            );
            $server->run($group);
            $address = $server->address;
            \assert($address instanceof InetAddress);
            $failed = connect('127.0.0.1', $address->port);
            $client = connect('127.0.0.1', $address->port);

            try {
                $failed->write("GET /fail HTTP/1.1\r\nHost: local\r\n\r\n");
                Assert::same($error, $failure->await());
                $client->write("GET / HTTP/1.1\r\nHost: local\r\n\r\n");

                $buffer = new Buffer();
                $buffer->fillUntil($client, "\r\n\r\n");
                Assert::same('HTTP/1.1 204 No Content', $buffer->readUntil("\r\n\r\n"));

                $server->close();
                Assert::null($client->read(1));
            } finally {
                $failed->close();
                $server->close();
                $client->close();
            }
        }));
    }

    public function rejectsASecondRun(): void
    {
        group(static function (Group $group) {
            $server = new Server(new Config(new InetAddress('127.0.0.1', 0)), static fn() => new Response());
            $server->run($group);

            Expect::exception(\LogicException::class);
            $server->run($group);
        });
    }

    public function endingGroupImmediatelyClosesTheServer(): void
    {
        $server = new Server(new Config(new InetAddress('127.0.0.1', 0)), static fn() => new Response());
        group($server->run(...));
        Assert::null($server->address);
    }

    public function cancellationClosesTheListener(): void
    {
        $server = new Server(new Config(new InetAddress('127.0.0.1', 0)), static fn() => new Response());
        $expired = new \stdClass();
        $result = timeoutOr(
            duration: ms(10),
            body: static fn() => group(static function (Group $group) use ($server) {
                $server->run($group);
                delay(sec(10));
            }),
            default: $expired,
        );

        Assert::same($expired, $result);
        Assert::null($server->address);
    }

    public function bindsOnlyOnRunAndCanRetryAfterBindFailure(): void
    {
        group(static function (Group $group) {
            $occupied = listen('127.0.0.1', 0);
            $address = $occupied->address;
            \assert($address instanceof InetAddress);
            $server = new Server(new Config(new TcpEndpoint($address, backlog: 16)), static fn() => new Response());
            Assert::null($server->address);

            try {
                $server->run($group);
                Assert::fail('The port is already occupied.');
            } catch (ListenFailed) {
                Assert::null($server->address);
            } finally {
                $occupied->close();
            }

            $server->run($group);
            Assert::equals($server->address, $address);
        });
    }

    public function createsUnixSocketDuringRunAndRemovesItOnClose(): void
    {
        $path = sys_get_temp_dir() . '/http-' . bin2hex(random_bytes(8));
        $server = new Server(new Config(new UnixEndpoint($path, backlog: 16)), static fn() => new Response());
        Assert::false(file_exists($path));

        group(static function (Group $group) use ($server, $path) {
            $server->run($group);
            Assert::true(file_exists($path));
            $server->close();
            Assert::false(file_exists($path));
        });
    }

    public function usesHttpsAndRejectsOtherNegotiatedProtocols(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'thesis-http-');
        \assert($file !== false);

        try {
            self::writeCertificate($file);
            $certificate = new Certificate($file);

            timeout(sec(2), static fn() => group(static function (Group $group) use ($certificate) {
                /** @var Deferred<array{?string, ?string}> $received */
                $received = new Deferred();
                /** @var Deferred<\Throwable> $failure */
                $failure = new Deferred();
                $tls = new ServerTls(certificate: $certificate, alpnProtocols: ['http/1.1', 'h2']);
                $server = new Server(
                    config: new Config(new InetAddress('127.0.0.1', 0), tls: $tls),
                    handler: static function (Request $request, Context $context) use ($received) {
                        $received->complete([$request->uri->getScheme(), $context->tls?->alpnProtocol]);

                        return new Response(Status::NO_CONTENT);
                    },
                    onError: static function (\Throwable $error) use ($failure) {
                        $failure->complete($error);
                    },
                );
                $server->run($group);
                $address = $server->address;
                \assert($address instanceof InetAddress);
                $client = connect(
                    host: '127.0.0.1',
                    port: $address->port,
                    tls: new ClientTls(
                        peerName: 'localhost',
                        caFile: $certificate->certFile,
                        alpnProtocols: ['http/1.1'],
                    ),
                );

                try {
                    $client->write("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
                    Assert::same(['https', 'http/1.1'], $received->await());

                    $unsupported = connect(
                        host: '127.0.0.1',
                        port: $address->port,
                        tls: new ClientTls(
                            peerName: 'localhost',
                            caFile: $certificate->certFile,
                            alpnProtocols: ['h2'],
                        ),
                    );

                    try {
                        Assert::instanceOf($failure->await(), TlsFailed::class);
                    } finally {
                        $unsupported->close();
                    }
                } finally {
                    $server->close();
                    $client->close();
                }

            }));
        } finally {
            @unlink($file);
        }
    }

    /**
     * @param non-empty-string $file
     */
    private static function writeCertificate(string $file): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        \assert($key instanceof \OpenSSLAsymmetricKey);
        $request = openssl_csr_new(['commonName' => 'localhost'], $key);
        \assert($key instanceof \OpenSSLAsymmetricKey);
        \assert($request instanceof \OpenSSLCertificateSigningRequest);
        $certificate = openssl_csr_sign($request, null, $key, days: 1, serial: 1);
        \assert($certificate instanceof \OpenSSLCertificate);
        $certificateExported = openssl_x509_export($certificate, $certificatePem);
        $keyExported = openssl_pkey_export($key, $keyPem);
        \assert($certificateExported && $keyExported && \is_string($certificatePem) && \is_string($keyPem));
        $written = file_put_contents($file, $certificatePem . $keyPem);
        \assert($written !== false);
    }
}
