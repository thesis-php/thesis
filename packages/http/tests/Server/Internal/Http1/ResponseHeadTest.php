<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Internal\Http1;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;
use Thesis\Http\Headers;
use Thesis\Http\Response;
use Thesis\Http\Server\Internal\Http1\Stub\StubUnknownLengthBody;
use Thesis\Http\StringBody;

#[Test]
#[Covers(ResponseHead::class)]
#[Covers(Fields::class)]
final readonly class ResponseHeadTest
{
    private const string DATE = 'Date: Thu, 01 Jan 1970 00:00:00 GMT';

    private \DateTimeImmutable $epoch;

    public function __construct()
    {
        $this->epoch = new \DateTimeImmutable('@0');
    }

    public function preservesAnExplicitDate(): void
    {
        $response = new Response(headers: new Headers(['date' => 'Mon, 02 Jan 2006 15:04:05 GMT']));
        $head = outgoingResponseHead($response, requestHead('GET / HTTP/1.1'));

        $expected = head('HTTP/1.1 200 OK', 'Date: Mon, 02 Jan 2006 15:04:05 GMT', 'Content-Length: 0', '', '');
        Assert::same($head->toString($this->epoch), $expected);
    }

    public function writesResponseWithBodyOfKnownLength(): void
    {
        $head = outgoingResponseHead(
            response: new Response(
                headers: new Headers(['content-type' => 'text/plain']),
                body: 'hello',
            ),
            request: requestHead('GET / HTTP/1.1'),
        );

        Assert::same($head->status, 200);
        Assert::same($head->version, Version::Http11);
        Assert::same($head->framing, Framing::Length);
        Assert::same($head->length, 5);
        Assert::true($head->isPersistent);
        Assert::same($head->upgrade, []);
        Assert::same($head->toString($this->epoch), head(
            'HTTP/1.1 200 OK',
            self::DATE,
            'Content-Type: text/plain',
            'Content-Length: 5',
            '',
            '',
        ));
    }

    public function writesEmptyBodyAsLengthOfZero(): void
    {
        $head = outgoingResponseHead(new Response(404), requestHead('GET / HTTP/1.1'));

        Assert::same($head->framing, Framing::Length);
        Assert::same(
            actual: $head->toString($this->epoch),
            expected: head('HTTP/1.1 404 Not Found', self::DATE, 'Content-Length: 0', '', ''),
        );
    }

    public function writesStatusWithoutKnownReasonWithEmptyOne(): void
    {
        $head = outgoingResponseHead(new Response(299), requestHead('GET / HTTP/1.1'));

        Assert::same($head->toString($this->epoch), head('HTTP/1.1 299 ', self::DATE, 'Content-Length: 0', '', ''));
    }

    public function writesBodyOfUnknownLengthInChunks(): void
    {
        $head = outgoingResponseHead(
            response: new Response(body: new StubUnknownLengthBody('abc')),
            request: requestHead('GET / HTTP/1.1'),
        );

        Assert::same($head->framing, Framing::Chunked);
        Assert::null($head->length);
        Assert::true($head->isPersistent);
        Assert::same(
            actual: $head->toString($this->epoch),
            expected: head('HTTP/1.1 200 OK', self::DATE, 'Transfer-Encoding: chunked', '', ''),
        );
    }

    public function endsBodyOfUnknownLengthWithConnectionForHttp10(): void
    {
        $head = outgoingResponseHead(
            response: new Response(body: new StubUnknownLengthBody('abc')),
            request: requestHead('GET / HTTP/1.0', 'Connection: keep-alive'),
        );

        Assert::same($head->framing, Framing::UntilClose);
        Assert::null($head->length);
        Assert::false($head->isPersistent);
        Assert::same($head->toString($this->epoch), head('HTTP/1.0 200 OK', self::DATE, '', ''));
    }

    public function usesChunkedFramingForAnEmptyReaderWithPendingTrailers(): void
    {
        $response = new Response(body: chunked("0\r\nX-End: yes\r\n\r\n"));
        $body = $response->takeBody();
        $head = ResponseHead::of($response, requestHead('GET / HTTP/1.1'), $body);

        Assert::same($head->framing, Framing::Chunked);
        Assert::null($head->length);
        Assert::same(
            actual: $head->toString($this->epoch),
            expected: head('HTTP/1.1 200 OK', self::DATE, 'Transfer-Encoding: chunked', '', ''),
        );
        Assert::same($body->readAll(), '');
        Assert::same($body->trailers()->get('x-end'), 'yes');
    }

    public function rejectsTrailersForHttp10(): void
    {
        $response = new Response(body: new StringBody('', new Headers(['x-count' => '1'])));
        Expect::exception(\ValueError::class)->withMessage('An HTTP/1.0 response cannot have trailers.');

        outgoingResponseHead($response, requestHead('GET / HTTP/1.0'));
    }

    public function rejectsTrailersForAResponseWithoutABody(): void
    {
        $response = new Response(204, body: new StringBody('', new Headers(['x-count' => '1'])));
        Expect::exception(\ValueError::class)->withMessage('A response without a body cannot have trailers.');

        outgoingResponseHead($response, requestHead('GET / HTTP/1.1'));
    }

    /**
     * @param list<string> $requestLines
     */
    #[DataProvider('connectionsOfRequests')]
    public function closesConnectionWhenRequestOrServerWantsTo(
        array $requestLines,
        bool $serverKeeps,
        bool $isPersistent,
        string $bytes,
    ): void {
        $head = outgoingResponseHead(
            response: new Response(body: 'a'),
            request: requestHead(...$requestLines),
            isPersistent: $serverKeeps,
        );

        Assert::same($head->isPersistent, $isPersistent);
        Assert::same($head->toString($this->epoch), $bytes);
    }

    /**
     * @return iterable<string, array{list<string>, bool, bool, string}>
     */
    public static function connectionsOfRequests(): iterable
    {
        $http11 = self::http11(...);
        $http10 = self::http10(...);

        yield 'HTTP/1.1' => [['GET / HTTP/1.1'], true, true, $http11()];
        yield 'HTTP/1.1, the request closes' => [
            ['GET / HTTP/1.1', 'Connection: close'],
            true,
            false,
            $http11('Connection: close'),
        ];
        yield 'HTTP/1.1, the server closes' => [['GET / HTTP/1.1'], false, false, $http11('Connection: close')];
        yield 'HTTP/1.0' => [['GET / HTTP/1.0'], true, false, $http10()];
        yield 'HTTP/1.0, the request keeps' => [
            ['GET / HTTP/1.0', 'Connection: keep-alive'],
            true,
            true,
            $http10('Connection: keep-alive'),
        ];
        yield 'HTTP/1.0, the server closes' => [['GET / HTTP/1.0', 'Connection: keep-alive'], false, false, $http10()];
    }

    #[DataProvider('responsesWithoutBody')]
    public function writesNoFramingOfResponseWithoutBody(string $requestLine, Response $response, string $bytes): void
    {
        $head = outgoingResponseHead($response, requestHead($requestLine));

        Assert::same($head->framing, Framing::None);
        Assert::same($head->length, 0);
        Assert::same($head->headers, $response->headers);
        Assert::true($head->isPersistent);
        Assert::same($head->toString($this->epoch), $bytes);
    }

    /**
     * @return iterable<string, array{string, Response, string}>
     */
    public static function responsesWithoutBody(): iterable
    {
        $length = new Headers(['content-length' => '1024']);

        yield 'HEAD' => ['HEAD / HTTP/1.1', new Response(), head('HTTP/1.1 200 OK', self::DATE, '', '')];
        yield 'HEAD with length' => [
            'HEAD / HTTP/1.1',
            new Response(headers: $length),
            head('HTTP/1.1 200 OK', self::DATE, 'Content-Length: 1024', '', ''),
        ];
        yield '304 with length' => [
            'GET / HTTP/1.1',
            new Response(304, $length),
            head('HTTP/1.1 304 Not Modified', self::DATE, 'Content-Length: 1024', '', ''),
        ];
        yield 'HEAD with zero representation length' => [
            'HEAD / HTTP/1.1',
            new Response(headers: new Headers(['content-length' => '0'])),
            head('HTTP/1.1 200 OK', self::DATE, 'Content-Length: 0', '', ''),
        ];
        yield '204' => ['GET / HTTP/1.1', new Response(204), head('HTTP/1.1 204 No Content', self::DATE, '', '')];
        yield '100' => ['POST / HTTP/1.1', new Response(100), head('HTTP/1.1 100 Continue', '', '')];
        yield '103' => [
            'GET / HTTP/1.1',
            new Response(103, new Headers(['link' => '</style.css>; rel=preload'])),
            head('HTTP/1.1 103 Early Hints', 'Link: </style.css>; rel=preload', '', ''),
        ];
        yield 'CONNECT' => [
            'CONNECT example.com:443 HTTP/1.1',
            new Response(),
            head('HTTP/1.1 200 OK', self::DATE, '', ''),
        ];
    }

    public function rejectsMalformedUpgradeVersion(): void
    {
        $request = requestHead('GET / HTTP/1.1');

        Assert::same(
            actual: failure(static fn() => outgoingResponseHead(new Response(101), $request, upgrade: ['HTTP/'])),
            expected: 'An Upgrade protocol must be a token with an optional token version, got "HTTP/".',
        );
    }

    public function switchesProtocols(): void
    {
        $head = outgoingResponseHead(
            response: new Response(101, new Headers(['sec-websocket-accept' => 'abc'])),
            request: requestHead('GET /chat HTTP/1.1', 'Connection: upgrade', 'Upgrade: websocket'),
            upgrade: ['websocket'],
        );

        Assert::same($head->upgrade, ['websocket']);
        Assert::same($head->toString($this->epoch), head(
            'HTTP/1.1 101 Switching Protocols',
            'Sec-Websocket-Accept: abc',
            'Upgrade: websocket',
            'Connection: upgrade',
            '',
            '',
        ));
    }

    #[DataProvider('invalidResponses')]
    public function refusesResponseItCannotWrite(string $requestLine, Response $response, string $message): void
    {
        Assert::same(failure(static fn() => outgoingResponseHead($response, requestHead($requestLine))), $message);
    }

    /**
     * @return iterable<string, array{string, Response, string}>
     */
    public static function invalidResponses(): iterable
    {
        $get = 'GET / HTTP/1.1';
        $connection = 'The header "%s" belongs to an HTTP/1.1 connection and is written by the codec.';
        $stream = new StubUnknownLengthBody('abc');

        foreach (['content-length', 'transfer-encoding', 'connection', 'keep-alive', 'upgrade'] as $name) {
            yield $name => [$get, new Response(headers: new Headers([$name => '1'])), \sprintf($connection, $name)];
        }

        yield '204 to HEAD with length' => [
            'HEAD / HTTP/1.1',
            new Response(204, new Headers(['content-length' => '0'])),
            \sprintf($connection, 'content-length'),
        ];
        yield '103 to HEAD with length' => [
            'HEAD / HTTP/1.1',
            new Response(103, new Headers(['content-length' => '0'])),
            \sprintf($connection, 'content-length'),
        ];
        yield 'HEAD with malformed length' => [
            'HEAD / HTTP/1.1',
            new Response(headers: new Headers(['content-length' => '-1'])),
            'Content-Length must be one number of at most 18 digits, got "-1".',
        ];
        yield '304 with repeated length' => [
            $get,
            new Response(304, new Headers(['content-length' => ['0', '1']])),
            'Content-Length must be one number of at most 18 digits, got "0, 1".',
        ];
        yield '304 with a representation length of 19 digits' => [
            $get,
            new Response(304, new Headers(['content-length' => '1000000000000000000'])),
            'Content-Length must be one number of at most 18 digits, got "1000000000000000000".',
        ];
        yield 'HEAD with coding' => [
            'HEAD / HTTP/1.1',
            new Response(headers: new Headers(['transfer-encoding' => 'chunked'])),
            \sprintf($connection, 'transfer-encoding'),
        ];
        yield '204 with length' => [
            $get,
            new Response(204, new Headers(['content-length' => '0'])),
            \sprintf($connection, 'content-length'),
        ];
        yield '100 with length' => [
            $get,
            new Response(100, new Headers(['content-length' => '0'])),
            \sprintf($connection, 'content-length'),
        ];
        yield 'CONNECT with length' => [
            'CONNECT example.com:443 HTTP/1.1',
            new Response(headers: new Headers(['content-length' => '0'])),
            \sprintf($connection, 'content-length'),
        ];
        yield 'HEAD with body' => [
            'HEAD / HTTP/1.1',
            new Response(body: 'hello'),
            'A 200 response to HEAD must have no body, got a body of 5 bytes.',
        ];
        yield 'HEAD with body of unknown length' => [
            'HEAD / HTTP/1.1',
            new Response(body: $stream),
            'A 200 response to HEAD must have no body, got a body of an unknown length.',
        ];
        yield '204 with body' => [
            $get,
            new Response(204, body: 'a'),
            'A 204 response to GET must have no body, got a body of 1 bytes.',
        ];
        yield '304 with body' => [
            $get,
            new Response(304, body: 'a'),
            'A 304 response to GET must have no body, got a body of 1 bytes.',
        ];
        yield '199 with body' => [
            $get,
            new Response(199, body: 'a'),
            'A 199 response to GET must have no body, got a body of 1 bytes.',
        ];
        yield 'CONNECT 299 with body' => [
            'CONNECT example.com:443 HTTP/1.1',
            new Response(299, body: 'a'),
            'A 299 response to CONNECT must have no body, got a body of 1 bytes.',
        ];
    }

    public function writesBodyOfRefusedConnect(): void
    {
        $head = outgoingResponseHead(
            response: new Response(300, body: 'a'),
            request: requestHead('CONNECT example.com:443 HTTP/1.1'),
        );

        Assert::same($head->framing, Framing::Length);
    }

    /**
     * @no-named-arguments
     */
    private static function http11(string ...$fields): string
    {
        return head('HTTP/1.1 200 OK', self::DATE, 'Content-Length: 1', ...$fields) . "\r\n\r\n";
    }

    /**
     * @no-named-arguments
     */
    private static function http10(string ...$fields): string
    {
        return head('HTTP/1.0 200 OK', self::DATE, 'Content-Length: 1', ...$fields) . "\r\n\r\n";
    }
}
