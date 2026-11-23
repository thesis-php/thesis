<?php

declare(strict_types=1);

namespace Thesis\HttpProtocol\Http1;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;
use Thesis\Http\Headers;
use Thesis\Http\Response;
use Thesis\Http\StringBody;
use Thesis\HttpProtocol\Http1\Stub\StubUnknownLengthBody;
use Thesis\HttpProtocol\Internal\Http1\Fields;

#[Test]
#[Covers(ResponseHead::class)]
#[Covers(Fields::class)]
final class ResponseHeadTest
{
    public function parsesResponseWithLength(): void
    {
        $head = ResponseHead::parse(
            bytes: head(
                'HTTP/1.1 200 OK',
                'Content-Type: text/plain',
                'Content-Length: 5',
                'Set-Cookie: a=1',
                'set-cookie: b=2',
            ),
            method: 'GET',
        );

        Assert::same($head->status, 200);
        Assert::same(lines($head->headers), [
            ['content-type', 'text/plain'],
            ['set-cookie', 'a=1'],
            ['set-cookie', 'b=2'],
        ]);
        Assert::same($head->version, Version::Http11);
        Assert::same($head->framing, Framing::Length);
        Assert::same($head->length, 5);
        Assert::true($head->isPersistent);
        Assert::same($head->upgrade, []);
    }

    #[DataProvider('statusLines')]
    public function parsesStatusLine(string $line, int $status, Version $version): void
    {
        $head = ResponseHead::parse(head($line, 'Content-Length: 0'), method: 'GET');

        Assert::same($head->status, $status);
        Assert::same($head->version, $version);
    }

    /**
     * @return iterable<string, array{string, int, Version}>
     */
    public static function statusLines(): iterable
    {
        yield 'reason' => ['HTTP/1.1 404 Not Found', 404, Version::Http11];
        yield 'no reason' => ['HTTP/1.1 200 ', 200, Version::Http11];
        yield 'no reason and no space' => ['HTTP/1.1 200', 200, Version::Http11];
        yield 'reason above ASCII' => ["HTTP/1.1 200 \xD0\x9E\xD0\x9A", 200, Version::Http11];
        yield 'reason with tab' => ["HTTP/1.1 200 O\tK", 200, Version::Http11];
        yield 'HTTP/1.0' => ['HTTP/1.0 200 OK', 200, Version::Http10];
        yield 'least status' => ['HTTP/1.1 100 Continue', 100, Version::Http11];
        yield 'greatest status' => ['HTTP/1.1 999 Custom', 999, Version::Http11];
    }

    #[DataProvider('malformedStatusLines')]
    public function rejectsMalformedStatusLine(string $line, string $quoted): void
    {
        Assert::same(
            actual: rejection(static fn() => ResponseHead::parse(head($line, 'Content-Length: 0'), method: 'GET')),
            expected: '400: A status line must be HTTP/1.0 or HTTP/1.1, a status of three digits and a reason phrase, '
            . "got {$quoted}.",
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedStatusLines(): iterable
    {
        yield 'empty' => ['', '""'];
        yield 'version only' => ['HTTP/1.1', '"HTTP/1.1"'];
        yield 'version and space' => ['HTTP/1.1 ', '"HTTP/1.1 "'];
        yield 'HTTP/2' => ['HTTP/2 200 OK', '"HTTP/2 200 OK"'];
        yield 'HTTP/1.2' => ['HTTP/1.2 200 OK', '"HTTP/1.2 200 OK"'];
        yield 'lowercase version' => ['http/1.1 200 OK', '"http/1.1 200 OK"'];
        yield 'two digits' => ['HTTP/1.1 20 OK', '"HTTP/1.1 20 OK"'];
        yield 'four digits' => ['HTTP/1.1 2000 OK', '"HTTP/1.1 2000 OK"'];
        yield 'status below 100' => ['HTTP/1.1 099 OK', '"HTTP/1.1 099 OK"'];
        yield 'status with letter' => ['HTTP/1.1 20x OK', '"HTTP/1.1 20x OK"'];
        yield 'status with sign' => ['HTTP/1.1 +20 OK', '"HTTP/1.1 +20 OK"'];
        yield 'two spaces' => ['HTTP/1.1  200 OK', '"HTTP/1.1  200 OK"'];
        yield 'tab' => ["HTTP/1.1\t200 OK", '"HTTP/1.1\t200 OK"'];
        yield 'tab before reason' => ["HTTP/1.1 200\tOK", '"HTTP/1.1 200\tOK"'];
        yield 'leading space' => [' HTTP/1.1 200 OK', '" HTTP/1.1 200 OK"'];
        yield 'bare LF' => ["HTTP/1.1 200 OK\nX: y", '"HTTP/1.1 200 OK\nX: y"'];
        yield 'bare CR in reason' => ["HTTP/1.1 200 O\rK", '"HTTP/1.1 200 O\rK"'];
        yield 'NUL after spaces of reason' => ["HTTP/1.1 404 Not Found \0", '"HTTP/1.1 404 Not Found \000"'];
        yield 'NUL in reason' => ["HTTP/1.1 200 O\0K", '"HTTP/1.1 200 O\000K"'];
    }

    /**
     * @param non-empty-string $method
     * @param list<string> $fields
     */
    #[DataProvider('framings')]
    public function findsEndOfBody(string $method, string $line, array $fields, Framing $framing, ?int $length): void
    {
        $head = ResponseHead::parse(head($line, ...$fields), $method);

        Assert::same($head->framing, $framing);
        Assert::same($head->length, $length);
        Assert::false($head->headers->has('transfer-encoding'));
    }

    /**
     * @return iterable<string, array{non-empty-string, string, list<string>, Framing, ?int}>
     */
    public static function framings(): iterable
    {
        $ok = 'HTTP/1.1 200 OK';
        $length = ['Content-Length: 7'];
        $chunked = ['Transfer-Encoding: chunked'];

        yield 'length' => ['GET', $ok, $length, Framing::Length, 7];
        yield 'empty' => ['GET', $ok, ['Content-Length: 0'], Framing::Length, 0];
        yield 'chunks' => ['GET', $ok, $chunked, Framing::Chunked, null];
        yield 'until close' => ['GET', $ok, [], Framing::UntilClose, null];
        yield 'HEAD with length' => ['HEAD', $ok, $length, Framing::None, 0];
        yield 'HEAD with chunks' => ['HEAD', $ok, $chunked, Framing::None, 0];
        yield 'HEAD without fields' => ['HEAD', $ok, [], Framing::None, 0];
        yield '100' => ['GET', 'HTTP/1.1 100 Continue', [], Framing::None, 0];
        yield '199' => ['GET', 'HTTP/1.1 199 Custom', $length, Framing::None, 0];
        yield '204' => ['GET', 'HTTP/1.1 204 No Content', $length, Framing::None, 0];
        yield '304' => ['GET', 'HTTP/1.1 304 Not Modified', $length, Framing::None, 0];
        yield '203' => ['GET', 'HTTP/1.1 203 Other', $length, Framing::Length, 7];
        yield '205' => ['GET', 'HTTP/1.1 205 Reset Content', $length, Framing::Length, 7];
        yield '303' => ['GET', 'HTTP/1.1 303 See Other', $length, Framing::Length, 7];
        yield '305' => ['GET', 'HTTP/1.1 305 Use Proxy', $length, Framing::Length, 7];
        yield 'CONNECT 200' => ['CONNECT', $ok, $length, Framing::None, 0];
        yield 'CONNECT 299' => ['CONNECT', 'HTTP/1.1 299 Custom', $chunked, Framing::None, 0];
        yield 'CONNECT 300' => ['CONNECT', 'HTTP/1.1 300 Multiple Choices', $length, Framing::Length, 7];
        yield 'CONNECT 407' => ['CONNECT', 'HTTP/1.1 407 Proxy Authentication Required', $length, Framing::Length, 7];
        yield 'lowercase head is another method' => ['head', $ok, $length, Framing::Length, 7];
    }

    /**
     * @param non-empty-string $method
     * @param list<string> $fields
     */
    #[DataProvider('lengthsOfAbsentBody')]
    public function keepsRepresentationLengthInHeaders(string $method, string $line, array $fields, ?string $length): void
    {
        $head = ResponseHead::parse(head($line, ...$fields), $method);

        Assert::same($head->headers->get('content-length'), $length);
    }

    /**
     * @return iterable<string, array{non-empty-string, string, list<string>, ?string}>
     */
    public static function lengthsOfAbsentBody(): iterable
    {
        yield 'HEAD' => ['HEAD', 'HTTP/1.1 200 OK', ['Content-Length: 007'], '7'];
        yield '204 to HEAD' => ['HEAD', 'HTTP/1.1 204 No Content', ['Content-Length: 7'], null];
        yield '103 to HEAD' => ['HEAD', 'HTTP/1.1 103 Early Hints', ['Content-Length: 7'], null];
        yield 'HEAD without length' => ['HEAD', 'HTTP/1.1 200 OK', [], null];
        yield '304' => ['GET', 'HTTP/1.1 304 Not Modified', ['Content-Length: 7'], '7'];
        yield '204' => ['GET', 'HTTP/1.1 204 No Content', ['Content-Length: 0'], null];
        yield '100' => ['GET', 'HTTP/1.1 100 Continue', ['Content-Length: 0'], null];
        yield 'CONNECT' => ['CONNECT', 'HTTP/1.1 200 OK', ['Content-Length: 7'], null];
        yield 'response with body' => ['GET', 'HTTP/1.1 200 OK', ['Content-Length: 7'], null];
    }

    /**
     * @param list<string> $fields
     */
    #[DataProvider('connections')]
    public function tellsWhetherConnectionStaysOpen(string $line, array $fields, bool $isPersistent): void
    {
        $head = ResponseHead::parse(head($line, ...$fields), method: 'GET');

        Assert::same($head->isPersistent, $isPersistent);
        Assert::false($head->headers->has('connection'));
    }

    /**
     * @return iterable<string, array{string, list<string>, bool}>
     */
    public static function connections(): iterable
    {
        yield 'length' => ['HTTP/1.1 200 OK', ['Content-Length: 5'], true];
        yield 'chunks' => ['HTTP/1.1 200 OK', ['Transfer-Encoding: chunked'], true];
        yield 'no body' => ['HTTP/1.1 204 No Content', [], true];
        yield 'close' => ['HTTP/1.1 200 OK', ['Content-Length: 5', 'Connection: close'], false];
        yield 'until close' => ['HTTP/1.1 200 OK', [], false];
        yield 'until close, keep-alive' => ['HTTP/1.1 200 OK', ['Connection: keep-alive'], false];
        yield 'HTTP/1.0' => ['HTTP/1.0 200 OK', ['Content-Length: 5'], false];
        yield 'HTTP/1.0 keep-alive' => ['HTTP/1.0 200 OK', ['Content-Length: 5', 'Connection: keep-alive'], true];
        yield 'HTTP/1.0 keep-alive until close' => ['HTTP/1.0 200 OK', ['Connection: keep-alive'], false];
    }

    public function listsProtocolsConnectionSwitchesTo(): void
    {
        $head = ResponseHead::parse(
            bytes: head(
                'HTTP/1.1 101 Switching Protocols',
                'Upgrade: websocket',
                'Connection: Upgrade',
                'Sec-WebSocket-Accept: abc',
            ),
            method: 'GET',
        );

        Assert::same($head->upgrade, ['websocket']);
        Assert::same($head->framing, Framing::None);
        Assert::same(lines($head->headers), [['sec-websocket-accept', 'abc']]);
    }

    public function parsesFieldsAsStrictlyAsOfRequest(): void
    {
        $parse = self::rejectionOfOk(...);

        Assert::same(
            actual: $parse('Content-Length: 5', 'Transfer-Encoding: chunked'),
            expected: '400: A message must not have both Content-Length and Transfer-Encoding.',
        );
        Assert::same($parse('X : y'), '400: A field line must be a token, a colon and a value, got "X : y".');
        Assert::same(
            actual: rejection(
                static fn() => ResponseHead::parse(head('HTTP/1.1 200 OK', 'A: 1', 'B: 2'), 'GET', maxFields: 1),
            ),
            expected: '431: A message must have at most 1 fields, got 2.',
        );
        Assert::same(
            actual: rejection(
                static fn() => ResponseHead::parse(head('HTTP/1.0 200 OK', 'Transfer-Encoding: chunked'), 'GET'),
            ),
            expected: '400: An HTTP/1.0 message must not have Transfer-Encoding.',
        );
        Assert::same(
            actual: rejection(
                static fn() => ResponseHead::parse(head('HTTP/1.1 200 OK', 'Content-Length: 5, 5'), 'HEAD'),
            ),
            expected: '400: Content-Length must be one number of at most 18 digits, got "5, 5".',
        );
    }

    public function writesResponseWithBodyOfKnownLength(): void
    {
        $head = ResponseHead::of(
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
        Assert::same($head->toString(), head(
            'HTTP/1.1 200 OK',
            'Content-Type: text/plain',
            'Content-Length: 5',
            '',
            '',
        ));
    }

    public function writesEmptyBodyAsLengthOfZero(): void
    {
        $head = ResponseHead::of(new Response(404), requestHead('GET / HTTP/1.1'));

        Assert::same($head->framing, Framing::Length);
        Assert::same($head->toString(), head('HTTP/1.1 404 Not Found', 'Content-Length: 0', '', ''));
    }

    public function writesStatusWithoutKnownReasonWithEmptyOne(): void
    {
        $head = ResponseHead::of(new Response(299), requestHead('GET / HTTP/1.1'));

        Assert::same($head->toString(), head('HTTP/1.1 299 ', 'Content-Length: 0', '', ''));
    }

    public function writesBodyOfUnknownLengthInChunks(): void
    {
        $head = ResponseHead::of(
            response: new Response(body: new StubUnknownLengthBody('abc')),
            request: requestHead('GET / HTTP/1.1'),
        );

        Assert::same($head->framing, Framing::Chunked);
        Assert::null($head->length);
        Assert::true($head->isPersistent);
        Assert::same($head->toString(), head('HTTP/1.1 200 OK', 'Transfer-Encoding: chunked', '', ''));
    }

    public function endsBodyOfUnknownLengthWithConnectionForHttp10(): void
    {
        $head = ResponseHead::of(
            response: new Response(body: new StubUnknownLengthBody('abc')),
            request: requestHead('GET / HTTP/1.0', 'Connection: keep-alive'),
        );

        Assert::same($head->framing, Framing::UntilClose);
        Assert::null($head->length);
        Assert::false($head->isPersistent);
        Assert::same($head->toString(), head('HTTP/1.0 200 OK', '', ''));
    }

    public function usesChunkedFramingForAnEmptyReaderWithPendingTrailers(): void
    {
        $response = new Response(body: chunked("0\r\nX-End: yes\r\n\r\n"));
        $head = ResponseHead::of($response, requestHead('GET / HTTP/1.1'));

        Assert::same($head->framing, Framing::Chunked);
        Assert::null($head->length);
        Assert::same($head->toString(), head('HTTP/1.1 200 OK', 'Transfer-Encoding: chunked', '', ''));
        Assert::same($response->body->discard()->get('x-end'), 'yes');
    }

    public function rejectsTrailersForHttp10(): void
    {
        $response = new Response(body: new StringBody('', new Headers(['x-count' => '1'])));
        Expect::exception(\ValueError::class)->withMessage('An HTTP/1.0 response cannot have trailers.');

        ResponseHead::of($response, requestHead('GET / HTTP/1.0'));
    }

    public function rejectsTrailersForAResponseWithoutABody(): void
    {
        $response = new Response(204, body: new StringBody('', new Headers(['x-count' => '1'])));
        Expect::exception(\ValueError::class)->withMessage('A response without a body cannot have trailers.');

        ResponseHead::of($response, requestHead('GET / HTTP/1.1'));
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
        $head = ResponseHead::of(
            response: new Response(body: 'a'),
            request: requestHead(...$requestLines),
            isPersistent: $serverKeeps,
        );

        Assert::same($head->isPersistent, $isPersistent);
        Assert::same($head->toString(), $bytes);
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
        $head = ResponseHead::of($response, requestHead($requestLine));

        Assert::same($head->framing, Framing::None);
        Assert::same($head->length, 0);
        Assert::same($head->headers, $response->headers);
        Assert::true($head->isPersistent);
        Assert::same($head->toString(), $bytes);
    }

    /**
     * @return iterable<string, array{string, Response, string}>
     */
    public static function responsesWithoutBody(): iterable
    {
        $length = new Headers(['content-length' => '1024']);

        yield 'HEAD' => ['HEAD / HTTP/1.1', new Response(), head('HTTP/1.1 200 OK', '', '')];
        yield 'HEAD with length' => [
            'HEAD / HTTP/1.1',
            new Response(headers: $length),
            head('HTTP/1.1 200 OK', 'Content-Length: 1024', '', ''),
        ];
        yield '304 with length' => [
            'GET / HTTP/1.1',
            new Response(304, $length),
            head('HTTP/1.1 304 Not Modified', 'Content-Length: 1024', '', ''),
        ];
        yield 'HEAD with zero representation length' => [
            'HEAD / HTTP/1.1',
            new Response(headers: new Headers(['content-length' => '0'])),
            head('HTTP/1.1 200 OK', 'Content-Length: 0', '', ''),
        ];
        yield '204' => ['GET / HTTP/1.1', new Response(204), head('HTTP/1.1 204 No Content', '', '')];
        yield '100' => ['POST / HTTP/1.1', new Response(100), head('HTTP/1.1 100 Continue', '', '')];
        yield '103' => [
            'GET / HTTP/1.1',
            new Response(103, new Headers(['link' => '</style.css>; rel=preload'])),
            head('HTTP/1.1 103 Early Hints', 'Link: </style.css>; rel=preload', '', ''),
        ];
        yield 'CONNECT' => ['CONNECT example.com:443 HTTP/1.1', new Response(), head('HTTP/1.1 200 OK', '', '')];
    }

    public function rejectsMalformedUpgradeVersion(): void
    {
        $request = requestHead('GET / HTTP/1.1');

        Assert::same(
            actual: failure(static fn() => ResponseHead::of(new Response(101), $request, upgrade: ['HTTP/'])),
            expected: 'An Upgrade protocol must be a token with an optional token version, got "HTTP/".',
        );
    }

    public function switchesProtocols(): void
    {
        $head = ResponseHead::of(
            response: new Response(101, new Headers(['sec-websocket-accept' => 'abc'])),
            request: requestHead('GET /chat HTTP/1.1', 'Connection: upgrade', 'Upgrade: websocket'),
            upgrade: ['websocket'],
        );

        Assert::same($head->upgrade, ['websocket']);
        Assert::same($head->toString(), head(
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
        Assert::same(failure(static fn() => ResponseHead::of($response, requestHead($requestLine))), $message);
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
        $head = ResponseHead::of(
            response: new Response(300, body: 'a'),
            request: requestHead('CONNECT example.com:443 HTTP/1.1'),
        );

        Assert::same($head->framing, Framing::Length);
    }

    #[DataProvider('heads')]
    public function writesHeadItHasParsed(string $bytes): void
    {
        Assert::same(ResponseHead::parse($bytes, method: 'GET')->toString(), $bytes . "\r\n\r\n");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function heads(): iterable
    {
        yield 'length' => [head('HTTP/1.1 200 OK', 'Content-Type: text/plain', 'Content-Length: 5')];
        yield 'chunks' => [head('HTTP/1.1 200 OK', 'Transfer-Encoding: chunked')];
        yield 'until close' => [head('HTTP/1.1 200 OK', 'Connection: close')];
        yield 'HTTP/1.0 until close' => [head('HTTP/1.0 200 OK')];
        yield 'HTTP/1.0 that stays open' => [head('HTTP/1.0 200 OK', 'Content-Length: 5', 'Connection: keep-alive')];
        yield 'no body' => [head('HTTP/1.1 204 No Content')];
        yield 'switch' => [head('HTTP/1.1 101 Switching Protocols', 'Upgrade: websocket', 'Connection: upgrade')];
    }

    /**
     * @no-named-arguments
     */
    private static function rejectionOfOk(string ...$fields): ?string
    {
        return rejection(static fn() => ResponseHead::parse(head('HTTP/1.1 200 OK', ...$fields), method: 'GET'));
    }

    /**
     * @no-named-arguments
     */
    private static function http11(string ...$fields): string
    {
        return head('HTTP/1.1 200 OK', 'Content-Length: 1', ...$fields) . "\r\n\r\n";
    }

    /**
     * @no-named-arguments
     */
    private static function http10(string ...$fields): string
    {
        return head('HTTP/1.0 200 OK', 'Content-Length: 1', ...$fields) . "\r\n\r\n";
    }
}
