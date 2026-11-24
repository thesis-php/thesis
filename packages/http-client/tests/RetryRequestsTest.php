<?php

declare(strict_types=1);

namespace Thesis\HttpClient;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\HttpProtocol\Http1\MalformedMessage;
use Thesis\Io\ReadFailed;
use Thesis\Io\WriteFailed;
use Thesis\Net\ConnectFailed;
use Thesis\Net\TlsFailed;
use Uri\Rfc3986\Uri;
use function Thesis\Net\socketPair;

#[Test]
#[Covers(RetryRequests::class)]
final class RetryRequestsTest
{
    public function retriesWhenThePeerClosesBeforeTheFirstHead(): void
    {
        $attempts = 0;
        $peers = [];
        $connect = static function () use (&$attempts, &$peers) {
            [$socket, $peer] = socketPair();
            $peers[] = $peer;
            if (++$attempts === 2) {
                $peer->write("HTTP/1.1 204 No Content\r\n\r\n");
            }
            $peer->closeWrite();

            return $socket;
        };
        $client = new Client(middleware: [new RetryRequests()], connector: $connect);
        Assert::same($client->send('http://example.org')->status, 204);
        Assert::same($attempts, 2);
    }

    public function stopsAfterAnIncompleteOrInformationalHead(): void
    {
        foreach (['HTTP/1.1 200', "HTTP/1.1 100 Continue\r\n\r\n"] as $wire) {
            $peers = [];
            $connect = static function () use ($wire, &$peers) {
                [$socket, $peer] = socketPair();
                $peers[] = $peer;
                $peer->write($wire);
                $peer->closeWrite();

                return $socket;
            };
            $client = new Client(middleware: [new RetryRequests()], connector: $connect);

            try {
                $client->send('http://example.org');
                Assert::fail('Expected an incomplete response to fail.');
            } catch (MalformedMessage) {
                Assert::same(\count($peers), 1);
            }
        }
    }

    public function replaysTheSamePutUploadWithinItsBudget(): void
    {
        $request = new Request('PUT', new Uri('http://example.com'), body: 'payload');
        $attempts = 0;
        $retry = new RetryRequests(maxRetries: 3);
        $response = $retry->handle($request, static function (Request $request) use (&$attempts) {
            Assert::same($request->body->read(16), 'payload');

            return match (++$attempts) {
                1 => throw new ConnectFailed(),
                2 => throw new WriteFailed(),
                3 => throw new ReadFailed(),
                default => new Response(status: 201),
            };
        });
        Assert::same($attempts, 4);
        Assert::same($response->status, 201);
    }

    public function propagatesTheLastFailure(): void
    {
        $request = new Request('GET', new Uri('http://example.com'));
        $attempts = 0;
        Expect::exception(ReadFailed::class);

        try {
            new RetryRequests()->handle($request, static function () use (&$attempts) {
                ++$attempts;

                throw new ReadFailed();
            });
        } finally {
            Assert::same($attempts, 2);
        }
    }

    public function preservesNonReplayableOperationsAndPermanentFailures(): void
    {
        $get = new Request('GET', new Uri('https://example.com'));
        $stream = new Internal\DecodedBody($get->body, ZLIB_ENCODING_GZIP);
        foreach ([
            [new Request('POST', $get->uri), new ConnectFailed()],
            [new Request('GET', $get->uri, body: $stream), new ReadFailed()],
            [$get, new TlsFailed()],
            [$get, new MalformedMessage('Invalid status line.')],
        ] as [$request, $failure]) {
            $attempts = 0;

            try {
                new RetryRequests()->handle($request, static function () use (&$attempts, $failure) {
                    ++$attempts;

                    throw $failure;
                });
            } catch (\Throwable $caught) {
                Assert::same($caught, $failure);
            }
            Assert::same($attempts, 1);
        }
    }
}
