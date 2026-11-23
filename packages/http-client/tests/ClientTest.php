<?php

declare(strict_types=1);

namespace Thesis\HttpClient;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Deferred;
use Thesis\Async\Group;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Io\Buffer;
use Thesis\Io\StreamClosed;
use Thesis\Io\StringReader;
use Thesis\Net\InetAddress;
use Time\Duration;
use Uri\Rfc3986\Uri;
use function Thesis\Async\group;
use function Thesis\Async\timeout;
use function Thesis\Net\listen;

#[Test]
#[Covers(Client::class)]
final class ClientTest
{
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

            $request = new Request('POST', new Uri("http://{$address}/upload?q=1"), body: new StringReader('data'));
            $end = new Client()->withResponse($request, static function (Response $response) {
                Assert::false(new \ReflectionObject($response->body)->isCloneable());
                Assert::null($response->trailers);
                Assert::same($response->body->read(2), 'ok');

                return $response->discardBodyAndReadTrailers()->get('x-end');
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
                $next->write("HTTP/1.1 204 No Content\r\n\r\n");
                $next->close();
                $listener->close();
            });

            $client = new Client();
            $request = new Request('GET', new Uri("http://{$listener->address}/"));
            $first = $client->send($request);
            Assert::same($first->body->read(1), 'x');
            $second = $client->send($request);
            $first->closeBody();
            Assert::same($second->body->read(1), 'y');
            Assert::same($second->discardBodyAndReadTrailers()->get('x-end'), 'yes');
            Assert::same($client->send($request)->status, 204);
            Assert::same($client->send($request)->status, 200);
            Assert::same($client->send($request)->status, 204);
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
            $request = new Request('GET', new Uri("http://{$listener->address}/"));
            $first = $client->send($request);
            Assert::same($first->body->read(1), 'a');
            Assert::same($client->send($request)->status, 201);
            Assert::same($client->send($request)->status, 202);
            Assert::same($first->body->read(1), 'b');
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
            $request = new Request('GET', new Uri("http://{$listener->address}/"));
            Assert::same($client->send($request)->status, 204);
            $send = static fn() => $client->send($request)->body->read(1);
            $first = $group->spawn($send);
            $second = $group->spawn($send);
            Assert::same($first->await(), 'x');
            Assert::same($second->await(), 'x');
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
            $saved->body->read(1);
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
            $reader = $response->body;
            $sendBody->complete();
            Assert::same($reader->read(1), 'x');
            if (!$explicitClose) {
                unset($response, $reader);
                $server->await();

                return;
            }

            $response->closeBody();
            $response->closeBody();
            $server->await();
            Expect::exception(StreamClosed::class);
            $reader->read(1);
        });
    }
}
