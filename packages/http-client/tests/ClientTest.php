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
use Thesis\Io\UnexpectedEof;
use Thesis\Net\InetAddress;
use Uri\Rfc3986\Uri;
use function Thesis\Async\group;
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
                Assert::null($response->trailers);
                Assert::same($response->body->read(2), 'ok');

                return $response->discardBodyAndReadTrailers()->get('x-end');
            });
            Assert::same($end, 'yes');
        });
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
                    $peer->read(1);
                    Assert::fail('Expected the client to close the connection.');
                } catch (UnexpectedEof) {
                    // The callback abandoned the body.
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

                    try {
                        $peer->read(1);
                        Assert::fail('Expected the client to close the connection.');
                    } catch (UnexpectedEof) {
                        // The client released the unfinished body.
                    }
                } finally {
                    $peer->close();
                    $listener->close();
                }
            });

            $response = new Client()->send(new Request('GET', new Uri("http://{$address}/")));
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
