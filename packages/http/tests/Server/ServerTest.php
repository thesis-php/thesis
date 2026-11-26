<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Deferred;
use Thesis\Async\Group;
use Thesis\Dispatcher;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Server;
use Thesis\Http\Server\Stub\TextErrorHandler;
use Thesis\Http\Status;
use Thesis\Io\Buffer;
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
    public function disconnectCancelsAWaitingHandlerAfterItsRequestBody(): void
    {
        foreach ([
            "GET / HTTP/1.1\r\nHost: local\r\n\r\n",
            "POST / HTTP/1.1\r\nHost: local\r\nContent-Length: 1\r\n\r\nx",
            "POST / HTTP/1.1\r\nHost: local\r\nTransfer-Encoding: chunked\r\n\r\n1\r\nx\r\n0\r\n\r\n",
        ] as $wire) {
            timeout(sec(2), static fn() => group(static function (Group $group) use ($wire) {
                /** @var Deferred<null> $entered */
                $entered = new Deferred();
                /** @var Deferred<Event\RequestEnded> $ended */
                $ended = new Deferred();
                $payload = null;
                $server = new Server(
                    config: new Config(0),
                    handler: static function (Request $request) use ($entered, &$payload) {
                        $entered->complete();
                        if ($request->method === 'POST') {
                            // Leave the final byte for the body reader while the watcher receives the half-close.
                            delay(ms(20));
                            $payload = $request->takeBody()->readAll();
                        }

                        delay(sec(3600));

                        return new Response();
                    },
                    dispatcher: new Dispatcher([static function (Event\RequestEnded $event) use ($ended) {
                        $ended->complete($event);
                    }]),
                );
                $server->start($group);
                $address = $server->address;
                \assert($address instanceof InetAddress);
                $client = connect('127.0.0.1', $address->port);
                $client->write($wire);
                $entered->await();
                $client->closeWrite();
                $event = $ended->await();
                $client->close();
                Assert::same($payload, str_starts_with($wire, 'POST') ? 'x' : null);
                Assert::null($event->status);
                Assert::false($event->responseWritten);
                $server->stop();
            }));
        }
    }

    public function keepsAPipelinedRequestSentWhileTheHandlerWaits(): void
    {
        timeout(sec(2), static fn() => group(static function (Group $group) {
            /** @var Deferred<null> $entered */
            $entered = new Deferred();
            $server = new Server(
                config: new Config(0),
                handler: static function (Request $request) use ($entered) {
                    $path = $request->uri->getRawPath();
                    if ($path === '/first') {
                        $entered->complete();
                        delay(ms(250));
                    }

                    return new Response(body: $path);
                },
            );
            $server->start($group);
            $address = $server->address;
            \assert($address instanceof InetAddress);
            $client = connect('127.0.0.1', $address->port);

            try {
                $client->write("GET /first HTTP/1.1\r\nHost: local\r\n\r\n");
                $entered->await();
                $client->write("GET /second HTTP/1.1\r\nHost: local\r\nConnection: close\r\n\r\n");
                $client->closeWrite();
                $bytes = '';

                while (($chunk = $client->read(8192)) !== null) {
                    $bytes .= $chunk;
                }

                Assert::same(substr_count($bytes, 'HTTP/1.1 200 OK'), 2);
                Assert::true(str_contains($bytes, '/firstHTTP/1.1'));
                Assert::true(str_ends_with($bytes, '/second'));
            } finally {
                $client->close();
                $server->stop();
            }
        }));
    }

    public function observesTheServerConnectionRouteAndExchange(): void
    {
        timeout(sec(2), static fn() => group(static function (Group $group) {
            $events = [];
            $handled = null;
            $observe = static function (object $event) use (&$events) {
                $events[] = $event;
            };
            $server = new Server(
                config: new Config(0),
                handler: new Router([new Route('GET', '/{id}', static function (Request $request) use (&$handled) {
                    $handled = $request;

                    return new Response(Status::NO_CONTENT);
                })]),
                dispatcher: new Dispatcher([$observe]),
            );
            $server->start($group);
            $address = $server->address;
            \assert($address instanceof InetAddress);
            $client = connect('127.0.0.1', $address->port);

            try {
                $client->write(
                    "HEAD /42 HTTP/1.1\r\nHost: local\r\n\r\n"
                    . "GET /no/match HTTP/1.1\r\nHost: local\r\nConnection: close\r\n\r\n",
                );

                while ($client->read(8192) !== null);
            } finally {
                $client->close();
                $server->stop();
            }

            Assert::same(array_map(get_class(...), $events), [
                Event\ServerStarted::class,
                Event\ConnectionAccepted::class,
                Event\RequestStarted::class,
                Event\RouteMatched::class,
                Event\RequestEnded::class,
                Event\RequestStarted::class,
                Event\RequestEnded::class,
                Event\ConnectionClosed::class,
                Event\ServerStopping::class,
                Event\ServerStopped::class,
            ]);
            $route = $events[3] ?? null;
            \assert($route instanceof Event\RouteMatched);
            Assert::same($route->request->method, 'HEAD');
            Assert::same($route->match->route->pattern, '/{id}');
            $started = $events[2] ?? null;
            \assert($started instanceof Event\RequestStarted);
            $ended = $events[4] ?? null;
            \assert($ended instanceof Event\RequestEnded);
            Assert::same($handled, $started->request);
            Assert::same($route->request, $started->request);
            Assert::same($ended->request, $started->request);

            Assert::same($ended->status, Status::NO_CONTENT);
            Assert::true($ended->responseWritten);
            $missing = $events[6] ?? null;
            \assert($missing instanceof Event\RequestEnded);
            Assert::same($missing->status, Status::NOT_FOUND);
        }));
    }

    public function routerUsesTheServersErrorHandler(): void
    {
        timeout(sec(2), static fn() => group(static function (Group $group) {
            $server = new Server(new Config(0), new Router([]), errorHandler: new TextErrorHandler());
            $server->start($group);
            $address = $server->address;
            \assert($address instanceof InetAddress);
            $client = connect('127.0.0.1', $address->port);

            try {
                $client->write("GET /missing HTTP/1.1\r\nHost: local\r\nConnection: close\r\n\r\n");
                $bytes = '';

                while (($chunk = $client->read(8192)) !== null) {
                    $bytes .= $chunk;
                }

                Assert::same(
                    actual: $bytes,
                    expected: "HTTP/1.1 404 Not Found\r\nContent-Length: 7\r\nConnection: close\r\n\r\nGET 404",
                );
            } finally {
                $client->close();
                $server->stop();
            }
        }));
    }

    public function aClientThatStopsReadingExpiresAtTheWriteDeadline(): void
    {
        timeout(sec(2), static fn() => group(static function (Group $group) {
            /** @var Deferred<\Throwable> $failure */
            $failure = new Deferred();
            /** @var Deferred<Event\RequestEnded> $exchange */
            $exchange = new Deferred();
            $onFinish = static function (Event\RequestEnded $event) use ($exchange) {
                $exchange->complete($event);
            };
            $observe = static function (Event\ConnectionHandlingFailed $event) use ($failure) {
                $failure->complete($event->error);
            };
            $server = new Server(
                config: new Config(0, idleTimeout: sec(5), writeTimeout: ms(20)),
                handler: static fn() => new Response(body: str_repeat('x', 16 * 1024 * 1024)),
                dispatcher: new Dispatcher([$observe, $onFinish]),
            );
            $server->start($group);
            $address = $server->address;
            \assert($address instanceof InetAddress);
            $client = connect('127.0.0.1', $address->port);

            try {
                $client->write("GET / HTTP/1.1\r\nHost: local\r\n\r\n");
                $error = $failure->await();
                Assert::instanceOf($error, WriteFailed::class);
                $finished = $exchange->await();
                Assert::same($finished->status, Status::OK);
                Assert::false($finished->responseWritten);
                Assert::same('The response write did not complete in time.', $error->getMessage());
            } finally {
                $client->close();
                $server->stop();
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
                config: new Config(endpoints: [
                    new TcpEndpoint(new InetAddress('127.0.0.1', 0), maxConnections: 1),
                    new TcpEndpoint(new InetAddress('127.0.0.1', 0)),
                ]),
                handler: static function (Request $request, Context $context) use ($entered, $release) {
                    $entered->complete($context);
                    $release->await();

                    return new Response(Status::NO_CONTENT);
                },
            );
            $server->start($group);
            $address = $server->address;
            \assert($address instanceof InetAddress);

            $client = connect('127.0.0.1', $address->port);

            try {
                $client->write("GET / HTTP/1.1\r\nHost: local\r\n\r\n");
                $context = $entered->await();
                Assert::same((string) $address, (string) $context->localAddress);
                Assert::same((string) $client->localAddress, (string) $context->remoteAddress);
                Assert::null($context->tls);

                /** @var Deferred<null> $waiting */
                $waiting = new Deferred();
                $stopped = $group->spawn(static function () use ($server, $waiting) {
                    $waiting->complete();
                    $server->stop();
                });
                $waiting->await();
                Assert::null($server->address);
                Assert::same($server->addresses, []);
                Assert::false($stopped->isDone);
                $release->complete();
                $stopped->await();
                $server->stop();

                $buffer = new Buffer();
                $buffer->fillUntil($client, "\r\n\r\n");
                Assert::same(
                    "HTTP/1.1 204 No Content\r\nConnection: close",
                    $buffer->readUntil("\r\n\r\n"),
                );
                Assert::null($client->read(1));
            } finally {
                $release->complete();
                $server->stop();
                $client->close();
            }
        }));
    }

    public function cancelsAStalledHandlerWhenShutdownExpires(): void
    {
        timeout(sec(2), static fn() => group(static function (Group $group) {
            /** @var Deferred<null> $entered */
            $entered = new Deferred();
            /** @var Deferred<int> $forced */
            $forced = new Deferred();
            $onStop = static function (Event\ServerStopped $event) use ($forced) {
                $forced->complete($event->forcedConnections);
            };
            $server = new Server(
                config: new Config(0, shutdownTimeout: ms(10)),
                handler: static function () use ($entered) {
                    $entered->complete();
                    delay(sec(10));

                    return new Response(Status::NO_CONTENT);
                },
                dispatcher: new Dispatcher([$onStop]),
            );
            $server->start($group);
            $address = $server->address;
            \assert($address instanceof InetAddress);
            $client = connect('127.0.0.1', $address->port);

            try {
                $client->write("GET / HTTP/1.1\r\nHost: local\r\n\r\n");
                $entered->await();
                $server->stop();

                Assert::same($forced->await(), 1);
                Assert::null($client->read(1));
            } finally {
                $server->stop();
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
            $server->start($group);
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
                $server->stop();
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
            /** @var Deferred<Event\RequestEnded> $exchange */
            $exchange = new Deferred();
            $onFinish = static function (Event\RequestEnded $event) use ($exchange) {
                $exchange->complete($event);
            };
            $observe = static function (Event\ConnectionHandlingFailed $event) use ($failure) {
                $failure->complete($event->error);
            };
            $server = new Server(
                config: new Config(new InetAddress('127.0.0.1', 0)),
                handler: static function (Request $request) use ($error) {
                    if ($request->uri->getRawPath() === '/fail') {
                        throw $error;
                    }

                    return new Response(Status::NO_CONTENT);
                },
                dispatcher: new Dispatcher([$observe, $onFinish]),
            );
            $server->start($group);
            $address = $server->address;
            \assert($address instanceof InetAddress);
            $failed = connect('127.0.0.1', $address->port);
            $client = connect('127.0.0.1', $address->port);

            try {
                $failed->write("GET /fail HTTP/1.1\r\nHost: local\r\n\r\n");
                Assert::same($error, $failure->await());
                $finished = $exchange->await();
                Assert::same($finished->status, Status::INTERNAL_SERVER_ERROR);
                Assert::true($finished->responseWritten);
                $client->write("GET / HTTP/1.1\r\nHost: local\r\n\r\n");

                $buffer = new Buffer();
                $buffer->fillUntil($client, "\r\n\r\n");
                Assert::same('HTTP/1.1 204 No Content', $buffer->readUntil("\r\n\r\n"));

                $server->stop();
                Assert::null($client->read(1));
            } finally {
                $failed->close();
                $server->stop();
                $client->close();
            }
        }));
    }

    public function rejectsASecondRun(): void
    {
        group(static function (Group $group) {
            $server = new Server(new Config(new InetAddress('127.0.0.1', 0)), static fn() => new Response());
            $server->start($group);

            Expect::exception(\LogicException::class);
            $server->start($group);
        });
    }

    public function endingGroupImmediatelyClosesTheServer(): void
    {
        $server = new Server(new Config(new InetAddress('127.0.0.1', 0)), static fn() => new Response());
        group($server->start(...));
        Assert::null($server->address);
    }

    public function cancellationClosesTheListener(): void
    {
        $server = new Server(new Config(new InetAddress('127.0.0.1', 0)), static fn() => new Response());
        $expired = new \stdClass();
        $result = timeoutOr(
            duration: ms(10),
            body: static fn() => group(static function (Group $group) use ($server) {
                $server->start($group);
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
            $path = sys_get_temp_dir() . '/http-' . bin2hex(random_bytes(8));
            $server = new Server(
                config: new Config(endpoints: [new UnixEndpoint($path), new TcpEndpoint($address)]),
                handler: static fn() => new Response(),
            );
            Assert::null($server->address);

            try {
                $server->start($group);
                Assert::fail('The port is already occupied.');
            } catch (ListenFailed) {
                Assert::false(file_exists($path));
                Assert::same($server->addresses, []);
                Assert::null($server->address);
            } finally {
                $occupied->close();
            }

            $server->start($group);
            Assert::equals($server->addresses[1] ?? null, $address);
            $server->stop();
            Assert::same($server->addresses, []);
        });
    }

    public function createsUnixSocketDuringRunAndRemovesItOnClose(): void
    {
        $path = sys_get_temp_dir() . '/http-' . bin2hex(random_bytes(8));
        $server = new Server(new Config(new UnixEndpoint($path, backlog: 16)), static fn() => new Response());
        Assert::false(file_exists($path));

        group(static function (Group $group) use ($server, $path) {
            $server->start($group);
            Assert::true(file_exists($path));
            $server->stop();
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
                $observe = static function (Event\TlsHandshakeFailed $event) use ($failure) {
                    $failure->complete($event->error);
                };
                $server = new Server(
                    config: new Config(endpoints: [
                        new TcpEndpoint(new InetAddress('127.0.0.1', 0)),
                        new TcpEndpoint(new InetAddress('127.0.0.1', 0), tls: $tls),
                    ]),
                    handler: static function (Request $request, Context $context) use ($received) {
                        if ($context->tls !== null) {
                            $received->complete([$request->uri->getScheme(), $context->tls->alpnProtocol]);
                        }

                        return new Response(Status::NO_CONTENT);
                    },
                    dispatcher: new Dispatcher([$observe]),
                );
                $server->start($group);
                $addresses = $server->addresses;
                \assert(\count($addresses) === 2);
                [$plainAddress, $address] = $addresses;
                \assert($plainAddress instanceof InetAddress);
                $plain = connect('127.0.0.1', $plainAddress->port);
                $plain->write("GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
                $buffer = new Buffer();
                $buffer->fillUntil($plain, "\r\n");
                Assert::same($buffer->readUntil("\r\n"), 'HTTP/1.1 204 No Content');
                $plain->close();
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
                    $server->stop();
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
