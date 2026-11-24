<?php

declare(strict_types=1);

namespace Thesis\Http\Client;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Deferred;
use Thesis\Async\Group;
use Thesis\Async\TimedOut;
use Thesis\Http\Client;
use Thesis\Http\Internal\Http1\MalformedMessage;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Io\Buffer;
use Thesis\Io\StreamClosed;
use Thesis\Net\InetAddress;
use Time\Duration;
use Uri\Rfc3986\Uri;
use function Thesis\Async\group;
use function Thesis\Async\timeout;
use function Thesis\Net\listen;
use function Thesis\Net\socketPair;
use const Thesis\Http\EMPTY_BODY;

#[Test]
#[Covers(Client::class)]
final class ClientTest
{
    public function informationalAndFinalHeadsShareOneByteLimit(): void
    {
        $wire = "HTTP/1.1 103 Early Hints\r\n\r\nHTTP/1.1 204 No Content\r\n\r\n";
        foreach ([\strlen($wire), \strlen($wire) - 1] as $limit) {
            [$socket, $peer] = \Thesis\Net\socketPair();
            $peer->write($wire);
            $client = new Client(connector: static fn() => $socket, maxResponseHeadBytes: $limit);
            if ($limit === \strlen($wire)) {
                Assert::same($client->send('http://example.test')->status, 204);
            } else {
                Expect::exception(MalformedMessage::class);
                $client->send('http://example.test');
            }
        }
    }

    public function cancellingAWholeBodyReadReleasesTheOriginSlot(): void
    {
        [$first, $peer] = socketPair();
        [$second, $nextPeer] = socketPair();
        $peer->write("HTTP/1.1 200 OK\r\nContent-Length: 10\r\n\r\nx");
        $nextPeer->write("HTTP/1.1 204 No Content\r\n\r\n");
        $sockets = [$second, $first];
        $client = new Client(maxConnectionsPerOrigin: 1, connector: static function () use (&$sockets) {
            return array_pop($sockets) ?? throw new \LogicException();
        });
        $body = $client->send('http://example.test')->takeBody();

        try {
            timeout(Duration::fromSeconds(0), $body->readAll(...));
            Assert::fail('The incomplete response must wait for more bytes.');
        } catch (TimedOut) {
            $next = timeout(Duration::fromSeconds(1), static fn() => $client->send('http://example.test'));
            Assert::same($next->status, 204);
        }
    }

    public function followsRedirectAndBuffersDecodedBodyOnOneConnection(): void
    {
        timeout(Duration::fromSeconds(3), static fn() => group(static function (Group $group) {
            $listener = listen('127.0.0.1', 0);
            $group->spawn(static function () use ($listener) {
                $peer = $listener->accept() ?? throw new \LogicException();
                $buffer = new Buffer();
                $buffer->fillUntil($peer, "\r\n\r\n");
                Assert::string($buffer->drain())->startsWith('GET /start HTTP/1.1');
                $peer->write("HTTP/1.1 302 Found\r\nLocation: /end\r\nContent-Length: 0\r\n\r\n");
                $buffer->fillUntil($peer, "\r\n\r\n");
                Assert::string($buffer->drain())->startsWith('GET /end HTTP/1.1')->contains('Accept-Encoding: gzip');
                $encoded = gzencode('done');
                \assert($encoded !== false);
                $length = \strlen($encoded);
                $peer->write("HTTP/1.1 200 OK\r\nContent-Encoding: gzip\r\nContent-Length: {$length}\r\n\r\n{$encoded}");
                $peer->close();
                $listener->close();
            });

            $client = new Client(maxConnectionsPerOrigin: 1);
            Assert::same($client->send("http://{$listener->address}/start")->takeBody()->readAll(), 'done');
        }));
    }

    public function sendsPostAndReadsChunkedResponseAfterEarlyHints(): void
    {
        group(static function (Group $group) {
            $listener = listen('127.0.0.1', 0);
            $address = $listener->address;
            \assert($address instanceof InetAddress);
            $group->spawn(static function () use ($listener) {
                $peer = $listener->accept() ?? throw new \LogicException();

                try {
                    $buffer = new Buffer();
                    $buffer->fillUntil($peer, "\r\n\r\n");
                    $head = $buffer->readUntil("\r\n\r\n");
                    \assert($head !== null);
                    Assert::string($head)->startsWith("POST /upload?q=1 HTTP/1.1\r\n")->contains('Content-Length: 4');
                    $buffer->fill($peer, 4);
                    Assert::same($buffer->read(4), 'data');
                    $peer->write("HTTP/1.1 103 Early Hints\r\n\r\nHTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n");
                    $peer->write("2\r\nok\r\n0\r\nX-End: yes\r\n\r\n");
                } finally {
                    $peer->close();
                    $listener->close();
                }
            });

            $request = new Request('POST', new Uri("http://{$address}/upload?q=1"), body: 'data');
            $end = new Client()->withResponse($request, static function (Response $response) {
                $body = $response->takeBody();
                Assert::false(new \ReflectionObject($body)->isCloneable());
                Assert::true($body->mayHaveTrailers);
                Assert::same($body->read(2), 'ok');

                Assert::null($body->read(1));

                return $body->trailers()->get('x-end');
            });
            Assert::same($end, 'yes');
        });
    }

    public function reusesTheConnectionAfterEachMessageBoundary(): void
    {
        timeout(Duration::fromSeconds(3), static fn() => group(static function (Group $group) {
            $listener = listen('127.0.0.1', 0);
            $group->spawn(static function () use ($listener) {
                $peer = $listener->accept() ?? throw new \LogicException();
                foreach ([
                    "HTTP/1.1 200 OK\r\nContent-Length: 1\r\n\r\nx",
                    "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n1\r\ny\r\n0\r\nX-End: yes\r\n\r\n",
                    "HTTP/1.1 204 No Content\r\n\r\n",
                    "HTTP/1.1 200 OK\r\nContent-Length: 7\r\n\r\n",
                    "HTTP/1.1 304 Not Modified\r\nContent-Length: 0\r\n\r\n",
                    "HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Length: 0\r\n\r\n",
                ] as $wire) {
                    $buffer = new Buffer();
                    $buffer->fillUntil($peer, "\r\n\r\n");
                    Assert::string($buffer->drain())->notContains('Connection: close');
                    $peer->write($wire);
                }

                Assert::null($peer->read(1));
                $peer->close();

                $next = $listener->accept() ?? throw new \LogicException();
                new Buffer()->fillUntil($next, "\r\n\r\n");
                $next->write("HTTP/1.1 200 OK\r\nConnection: close\r\n\r\nlast");
                $next->close();
                $listener->close();
            });

            $client = new Client();
            $uri = new Uri("http://{$listener->address}/");
            $first = $client->send(Request::get($uri))->takeBody();
            Assert::same($first->read(1), 'x');
            $second = $client->send(Request::get($uri))->takeBody();
            $first->close();
            Assert::same($second->read(1), 'y');
            Assert::null($second->read(1));
            Assert::same($second->trailers()->get('x-end'), 'yes');
            Assert::same($client->send(Request::get($uri))->status, 204);
            Assert::same($second->trailers()->get('x-end'), 'yes');
            $head = $client->send(new Request('HEAD', $uri));
            Assert::same($head->headers->get('content-length'), '7');
            $headBody = $head->takeBody();
            Assert::same($headBody, EMPTY_BODY);
            Assert::null($headBody->read(1));
            $notModified = $client->send(Request::get($uri));
            Assert::same($notModified->status, 304);
            Assert::same($notModified->headers->get('content-length'), '0');
            Assert::null($notModified->takeBody()->read(1));
            Assert::same($client->send(Request::get($uri))->status, 200);
            $last = $client->send(Request::get($uri))->takeBody();
            Assert::false($last->mayHaveTrailers);
            Assert::same($last->readAll(), 'last');
            Assert::same($last->trailers()->toArray(), []);
            Assert::null($last->read(1));
        }));
    }

    public function keepsAnUnreadBodyExclusiveAndReplacesAnIdleConnectionClosedByThePeer(): void
    {
        timeout(Duration::fromSeconds(3), static fn() => group(static function (Group $group) {
            $listener = listen('127.0.0.1', 0);
            $group->spawn(static function () use ($listener) {
                $first = $listener->accept() ?? throw new \LogicException();
                new Buffer()->fillUntil($first, "\r\n\r\n");
                $first->write("HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nab");
                foreach ([201, 202] as $status) {
                    $other = $listener->accept() ?? throw new \LogicException();
                    new Buffer()->fillUntil($other, "\r\n\r\n");
                    $other->write("HTTP/1.1 {$status}\r\nContent-Length: 0\r\n\r\n");
                    $other->close();
                }

                $first->close();
                $listener->close();
            });

            $client = new Client();
            $uri = new Uri("http://{$listener->address}/");
            $first = $client->send(Request::get($uri))->takeBody();
            Assert::same($first->read(1), 'a');
            Assert::same($client->send(Request::get($uri))->status, 201);
            Assert::same($client->send(Request::get($uri))->status, 202);
            Assert::same($first->read(1), 'b');
        }));
    }

    public function parallelRequestsReserveSeparateConnections(): void
    {
        timeout(Duration::fromSeconds(3), static fn() => group(static function (Group $group) {
            $listener = listen('127.0.0.1', 0);
            $group->spawn(static function () use ($listener) {
                $first = $listener->accept() ?? throw new \LogicException();
                new Buffer()->fillUntil($first, "\r\n\r\n");
                $first->write("HTTP/1.1 204 No Content\r\n\r\n");
                new Buffer()->fillUntil($first, "\r\n\r\n");

                // Hold the first response until the second request arrives on its own connection.
                $second = $listener->accept() ?? throw new \LogicException();
                new Buffer()->fillUntil($second, "\r\n\r\n");
                foreach ([$second, $first] as $peer) {
                    $peer->write("HTTP/1.1 200 OK\r\nContent-Length: 1\r\n\r\nx");
                    $peer->close();
                }

                $listener->close();
            });

            $client = new Client();
            $uri = new Uri("http://{$listener->address}/");
            Assert::same($client->send(Request::get($uri))->status, 204);
            $send = static fn() => $client->send(Request::get($uri))->takeBody()->read(1);
            $first = $group->spawn($send);
            $second = $group->spawn($send);
            Assert::same($first->await(), 'x');
            Assert::same($second->await(), 'x');
        }));
    }

    public function aQueuedRequestUsesTheConnectionReleasedByTheBody(): void
    {
        timeout(Duration::fromSeconds(3), static fn() => group(static function (Group $group) {
            $listener = listen('127.0.0.1', 0);
            $group->spawn(static function () use ($listener) {
                $peer = $listener->accept() ?? throw new \LogicException();
                new Buffer()->fillUntil($peer, "\r\n\r\n");
                $peer->write("HTTP/1.1 200 OK\r\nContent-Length: 1\r\n\r\nx");
                new Buffer()->fillUntil($peer, "\r\n\r\n");
                $peer->write("HTTP/1.1 204 No Content\r\n\r\n");
                $peer->close();
                $listener->close();
            });

            $client = new Client(maxConnectionsPerOrigin: 1);
            $uri = new Uri("http://{$listener->address}/");
            $first = $client->send(Request::get($uri))->takeBody();
            /** @var Deferred<null> $started */
            $started = new Deferred();
            $waiting = $group->spawn(static function () use ($client, $uri, $started) {
                $started->complete();

                return $client->send(Request::get($uri));
            });
            $started->await();
            Assert::false($waiting->isDone);
            Assert::same($first->read(1), 'x');
            Assert::same($waiting->await()->status, 204);
        }));
    }

    public function reusesBothIdleSocketsAfterConcurrentDownloads(): void
    {
        timeout(Duration::fromSeconds(3), static fn() => group(static function (Group $group) {
            $listener = listen('127.0.0.1', 0);
            $group->spawn(static function () use ($listener) {
                $peers = [];
                foreach (['a', 'b'] as $byte) {
                    $peer = $listener->accept() ?? throw new \LogicException();
                    $peers[] = $peer;
                    new Buffer()->fillUntil($peer, "\r\n\r\n");
                    $peer->write("HTTP/1.1 200 OK\r\nContent-Length: 1\r\n\r\n{$byte}");
                }

                foreach (array_reverse($peers) as $peer) {
                    new Buffer()->fillUntil($peer, "\r\n\r\n");
                    $peer->write("HTTP/1.1 200 OK\r\nContent-Length: 1\r\n\r\nx");
                    $peer->close();
                }

                $listener->close();
            });

            $client = new Client(maxConnectionsPerOrigin: 2);
            $uri = new Uri("http://{$listener->address}/");
            $first = $client->send(Request::get($uri))->takeBody();
            $second = $client->send(Request::get($uri))->takeBody();
            Assert::same($first->read(1), 'a');
            Assert::same($second->read(1), 'b');
            $third = $client->send(Request::get($uri))->takeBody();
            $fourth = $client->send(Request::get($uri))->takeBody();
            Assert::same($third->read(1), 'x');
            Assert::same($fourth->read(1), 'x');
        }));
    }

    public function closesAnUnfinishedBodyWhileTheResponseIsStillReferenced(): void
    {
        self::abandonBody(explicitClose: true);
    }

    public function releasesAnUnfinishedBodyWhenItsLastReaderIsDropped(): void
    {
        self::abandonBody(explicitClose: false);
    }

    public function closesTheBodyWhenTheCallbackThrows(): void
    {
        group(static function (Group $group) {
            $listener = listen('127.0.0.1', 0);
            $server = $group->spawn(static function () use ($listener) {
                $peer = $listener->accept() ?? throw new \LogicException();

                try {
                    new Buffer()->fillUntil($peer, "\r\n\r\n");
                    $peer->write("HTTP/1.1 200 OK\r\nContent-Length: 10\r\n\r\n");
                    Assert::null($peer->read(1));
                } finally {
                    $peer->close();
                    $listener->close();
                }
            });

            $request = new Request('GET', new Uri("http://{$listener->address}/"));
            $error = new \RuntimeException();
            $saved = null;

            try {
                new Client()->withResponse($request, static function (Response $response) use (&$saved, $error) {
                    $saved = $response;

                    throw $error;
                });
            } catch (\RuntimeException $caught) {
                Assert::same($caught, $error);
            }

            $server->await();
            \assert($saved instanceof Response);
            Expect::exception(StreamClosed::class);
            $saved->takeBody()->read(1);
        });
    }

    private static function abandonBody(bool $explicitClose): void
    {
        group(static function (Group $group) use ($explicitClose) {
            $listener = listen('127.0.0.1', 0);
            $address = $listener->address;
            \assert($address instanceof InetAddress);
            /** @var Deferred<null> $sendBody */
            $sendBody = new Deferred();
            $server = $group->spawn(static function () use ($listener, $sendBody) {
                $peer = $listener->accept() ?? throw new \LogicException();

                try {
                    new Buffer()->fillUntil($peer, "\r\n\r\n");
                    $peer->write("HTTP/1.1 200 OK\r\nContent-Length: 10\r\n\r\n");
                    $sendBody->await();
                    $peer->write('x');

                    Assert::null($peer->read(1));
                } finally {
                    $peer->close();
                    $listener->close();
                }
            });

            $client = new Client();
            $response = $client->send(new Request('GET', new Uri("http://{$address}/")));
            $reader = $response->takeBody();
            $sendBody->complete();
            Assert::same($reader->read(1), 'x');
            if (!$explicitClose) {
                unset($response, $reader);
                $server->await();

                return;
            }

            $reader->close();
            $reader->close();
            $server->await();
            Expect::exception(StreamClosed::class);
            $reader->read(1);
        });
    }
}
