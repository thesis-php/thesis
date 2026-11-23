<?php

declare(strict_types=1);

namespace Thesis\Http\Http1;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;
use Thesis\Http\Internal\Http1\Fields;
use function Thesis\Http\head;
use function Thesis\Http\lines;
use function Thesis\Http\rejection;

#[Test]
#[Covers(RequestHead::class)]
#[Covers(Fields::class)]
#[Covers(MalformedMessage::class)]
final class RequestHeadParseTest
{
    public function parsesRequestWithoutBody(): void
    {
        $head = RequestHead::parse(head(
            'GET /users/42?fields=name HTTP/1.1',
            'Host: example.com',
            'Accept: text/html',
            'User-Agent: test',
        ));

        Assert::same($head->method, 'GET');
        Assert::same($head->uri->toRawString(), 'http://example.com/users/42?fields=name');
        Assert::same(lines($head->headers), [['accept', 'text/html'], ['user-agent', 'test']]);
        Assert::same($head->version, Version::Http11);
        Assert::same($head->framing, Framing::None);
        Assert::same($head->length, 0);
        Assert::true($head->isPersistent);
        Assert::same($head->upgrade, []);
    }

    public function takesSchemeOfConnection(): void
    {
        $head = RequestHead::parse(head('GET / HTTP/1.1', 'Host: example.com'), scheme: 'https');

        Assert::same($head->uri->toRawString(), 'https://example.com/');
    }

    #[DataProvider('hosts')]
    public function takesHostAndPortOfHost(string $host, string $uri): void
    {
        $head = RequestHead::parse(head('GET /a HTTP/1.1', "Host: {$host}"));

        Assert::same($head->uri->toRawString(), $uri);
        Assert::false($head->headers->has('host'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hosts(): iterable
    {
        yield 'name' => ['example.com', 'http://example.com/a'];
        yield 'port' => ['example.com:8080', 'http://example.com:8080/a'];
        yield 'greatest port' => ['example.com:65535', 'http://example.com:65535/a'];
        yield 'empty port' => ['example.com:', 'http://example.com:/a'];
        yield 'uppercase' => ['EXAMPLE.com', 'http://EXAMPLE.com/a'];
        yield 'IPv4' => ['127.0.0.1:80', 'http://127.0.0.1:80/a'];
        yield 'spaces around' => ["  example.com\t", 'http://example.com/a'];
    }

    public function takesHostOfIpv6AsItWasSent(): void
    {
        $head = RequestHead::parse(head('GET /a HTTP/1.1', 'Host: [2001:db8::1]:8080'));

        Assert::same($head->uri->getRawHost(), '[2001:db8::1]');
        Assert::same($head->uri->getPort(), 8080);
        Assert::same($head->uri->getRawPath(), '/a');
    }

    public function keepsLinesOfFieldsWithNamesInLowercaseAndValuesTrimmed(): void
    {
        $head = RequestHead::parse(head(
            'GET / HTTP/1.1',
            'Host: example.com',
            "ACCEPT: \t text/html \t",
            'accept:text/plain',
            'X-Empty:',
            'X-Colons: a:b: c',
            '1: one',
        ));

        Assert::same(lines($head->headers), [
            ['accept', 'text/html'],
            ['accept', 'text/plain'],
            ['x-empty', ''],
            ['x-colons', 'a:b: c'],
            ['1', 'one'],
        ]);
    }

    public function framesBodyByContentLength(): void
    {
        $head = RequestHead::parse(head('POST / HTTP/1.1', 'Host: example.com', 'Content-Length: 42'));

        Assert::same($head->framing, Framing::Length);
        Assert::same($head->length, 42);
        Assert::false($head->headers->has('content-length'));
    }

    public function framesEmptyBodyByContentLengthOfZero(): void
    {
        $head = RequestHead::parse(head('POST / HTTP/1.1', 'Host: example.com', 'Content-Length: 0'));

        Assert::same($head->framing, Framing::Length);
        Assert::same($head->length, 0);
    }

    public function acceptsContentLengthOfEighteenDigits(): void
    {
        $head = RequestHead::parse(head('POST / HTTP/1.1', 'Host: a', 'Content-Length: 999999999999999999'));

        Assert::same($head->length, 999_999_999_999_999_999);
    }

    public function acceptsContentLengthWithLeadingZeros(): void
    {
        $head = RequestHead::parse(head('POST / HTTP/1.1', 'Host: a', 'Content-Length: 007'));

        Assert::same($head->length, 7);
    }

    #[DataProvider('chunkedCodings')]
    public function framesBodyByChunks(string $coding): void
    {
        $head = RequestHead::parse(head('POST / HTTP/1.1', 'Host: example.com', "Transfer-Encoding: {$coding}"));

        Assert::same($head->framing, Framing::Chunked);
        Assert::null($head->length);
        Assert::false($head->headers->has('transfer-encoding'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function chunkedCodings(): iterable
    {
        yield 'lowercase' => ['chunked'];
        yield 'mixed case' => ['Chunked'];
        yield 'uppercase' => ['CHUNKED'];
    }

    /**
     * @param list<string> $fields
     */
    #[DataProvider('connections')]
    public function tellsWhetherConnectionStaysOpen(string $version, array $fields, bool $isPersistent): void
    {
        $head = RequestHead::parse(head("GET / {$version}", 'Host: example.com', ...$fields));

        Assert::same($head->isPersistent, $isPersistent);
        Assert::false($head->headers->has('connection'));
    }

    /**
     * @return iterable<string, array{string, list<string>, bool}>
     */
    public static function connections(): iterable
    {
        yield 'HTTP/1.1 by default' => ['HTTP/1.1', [], true];
        yield 'HTTP/1.1 close' => ['HTTP/1.1', ['Connection: close'], false];
        yield 'HTTP/1.1 Close' => ['HTTP/1.1', ['Connection: Close'], false];
        yield 'HTTP/1.1 keep-alive' => ['HTTP/1.1', ['Connection: keep-alive'], true];
        yield 'HTTP/1.1 close among options' => ['HTTP/1.1', ['Connection: keep-alive, close'], false];
        yield 'HTTP/1.1 close on second line' => ['HTTP/1.1', ['Connection: x-a', 'Connection: close'], false];
        yield 'HTTP/1.1 close among empty elements' => ['HTTP/1.1', ['Connection: , ,close,'], false];
        yield 'HTTP/1.1 other option' => ['HTTP/1.1', ['Connection: upgrade'], true];
        yield 'HTTP/1.0 by default' => ['HTTP/1.0', [], false];
        yield 'HTTP/1.0 keep-alive' => ['HTTP/1.0', ['Connection: keep-alive'], true];
        yield 'HTTP/1.0 Keep-Alive' => ['HTTP/1.0', ['Connection: Keep-Alive'], true];
        yield 'HTTP/1.0 close' => ['HTTP/1.0', ['Connection: close'], false];
        yield 'HTTP/1.0 keep-alive and close' => ['HTTP/1.0', ['Connection: keep-alive, close'], false];
        yield 'HTTP/1.0 other option' => ['HTTP/1.0', ['Connection: upgrade'], false];
    }

    public function dropsFieldsOfConnectionAndThoseConnectionNames(): void
    {
        $head = RequestHead::parse(head(
            'GET / HTTP/1.1',
            'Host: example.com',
            'Connection: X-Hop, keep-alive',
            'Connection: TE',
            'Keep-Alive: timeout=5',
            'X-Hop: 1',
            'x-hop: 2',
            'TE: trailers',
            'Upgrade: websocket',
            'X-End-To-End: 3',
        ));

        Assert::same(lines($head->headers), [['x-end-to-end', '3']]);
        Assert::same($head->upgrade, []);
    }

    public function listsProtocolsOfUpgradeThatConnectionNames(): void
    {
        $head = RequestHead::parse(head(
            'GET /chat HTTP/1.1',
            'Host: example.com',
            'Connection: Upgrade',
            'Upgrade: websocket, ,h2c',
            'Upgrade: HTTP/2.0',
        ));

        Assert::same($head->upgrade, ['websocket', 'h2c', 'HTTP/2.0']);
        Assert::false($head->headers->has('upgrade'));
        Assert::true($head->isPersistent);
    }

    public function hasNoProtocolsWhenConnectionNamesUpgradeWithoutField(): void
    {
        $head = RequestHead::parse(head('GET / HTTP/1.1', 'Host: example.com', 'Connection: upgrade'));

        Assert::same($head->upgrade, []);
    }

    public function parsesRequestOfHttp10WithoutHost(): void
    {
        $head = RequestHead::parse(head('GET /health?deep=1 HTTP/1.0'));

        Assert::same($head->uri->toRawString(), '/health?deep=1');
        Assert::null($head->uri->getRawHost());
        Assert::same($head->version, Version::Http10);
    }

    public function takesHostOfRequestOfHttp10(): void
    {
        $head = RequestHead::parse(head('GET /health HTTP/1.0', 'Host: example.com'));

        Assert::same($head->uri->toRawString(), 'http://example.com/health');
    }

    public function parsesOptionsOfServerAsEmptyPath(): void
    {
        $head = RequestHead::parse(head('OPTIONS * HTTP/1.1', 'Host: example.com:8080'), scheme: 'https');

        Assert::same($head->uri->toRawString(), 'https://example.com:8080');
        Assert::same($head->uri->getRawPath(), '');
    }

    public function parsesOptionsOfServerOfHttp10WithoutHost(): void
    {
        $head = RequestHead::parse(head('OPTIONS * HTTP/1.0'));

        Assert::same($head->uri->toRawString(), '');
    }

    public function parsesOptionsOfPath(): void
    {
        $head = RequestHead::parse(head('OPTIONS /users HTTP/1.1', 'Host: example.com'));

        Assert::same($head->uri->toRawString(), 'http://example.com/users');
    }

    public function parsesTargetOfConnectAsHostAndPort(): void
    {
        $head = RequestHead::parse(head('CONNECT upstream.example:443 HTTP/1.1', 'Host: upstream.example:443'));

        Assert::same($head->uri->toRawString(), '//upstream.example:443');
        Assert::same($head->uri->getRawHost(), 'upstream.example');
        Assert::same($head->uri->getPort(), 443);
    }

    public function takesHostOfAbsoluteTargetNotOfHost(): void
    {
        $head = RequestHead::parse(head('GET https://other.example:8443/a?b HTTP/1.1', 'Host: example.com'));

        Assert::same($head->uri->toRawString(), 'https://other.example:8443/a?b');
        Assert::false($head->headers->has('host'));
    }

    public function parsesAbsoluteTargetWithoutPath(): void
    {
        $head = RequestHead::parse(head('GET HTTP://other.example?b HTTP/1.1', 'Host: example.com'));

        Assert::same($head->uri->toRawString(), 'HTTP://other.example?b');
    }

    public function keepsPathThatStartsWithTwoSlashesOnHostOfHost(): void
    {
        $head = RequestHead::parse(head('GET //evil.example/a HTTP/1.1', 'Host: example.com'));

        Assert::same($head->uri->getRawHost(), 'example.com');
        Assert::same($head->uri->getRawPath(), '//evil.example/a');
    }

    public function keepsTargetAsItWasSent(): void
    {
        $head = RequestHead::parse(head('GET /a/../b/./%7Ec%2fd//e?x=%41&y=a+b&&z HTTP/1.1', 'Host: example.com'));

        Assert::same($head->uri->getRawPath(), '/a/../b/./%7Ec%2fd//e');
        Assert::same($head->uri->getRawQuery(), 'x=%41&y=a+b&&z');
    }

    public function escapesBytesThatBrowsersSendUnescaped(): void
    {
        $head = RequestHead::parse(head('GET /a|b[1]?filter[name]=a|b&c={d}^`\ HTTP/1.1', 'Host: example.com'));

        Assert::same(
            actual: $head->uri->toRawString(),
            expected: 'http://example.com/a%7Cb%5B1%5D?filter%5Bname%5D=a%7Cb&c=%7Bd%7D%5E%60%5C',
        );
    }

    public function parsesAbsoluteTargetWithHostOfIpv6(): void
    {
        $head = RequestHead::parse(head('GET http://[::1]:8080/a?b HTTP/1.1', 'Host: example.com'));

        Assert::same($head->uri->getRawHost(), '[::1]');
        Assert::same($head->uri->getPort(), 8080);
        Assert::same($head->uri->getRawPath(), '/a');
        Assert::same($head->uri->getRawQuery(), 'b');
    }

    public function acceptsGreatestPortOfAbsoluteTarget(): void
    {
        $head = RequestHead::parse(head('GET http://other.example:65535/ HTTP/1.1', 'Host: example.com'));

        Assert::same($head->uri->getPort(), 65535);
    }

    public function acceptsAsManyFieldsAsLimit(): void
    {
        $head = RequestHead::parse(head('GET / HTTP/1.1', 'Host: example.com', 'A: 1', 'B: 2'), maxFields: 3);

        Assert::same(lines($head->headers), [['a', '1'], ['b', '2']]);
    }

    public function rejectsMoreFieldsThanLimit(): void
    {
        $bytes = head('GET / HTTP/1.1', 'Host: example.com', 'A: 1', 'B: 2');

        Assert::same(
            actual: rejection(static fn() => RequestHead::parse($bytes, maxFields: 2)),
            expected: '431: A message must have at most 2 fields, got 3.',
        );
    }

    public function rejectsMoreThanHundredFieldsByDefault(): void
    {
        $fields = array_map(static fn($number) => "X-{$number}: value", range(1, 100));

        Assert::null(rejection(static fn() => RequestHead::parse(head('GET / HTTP/1.0', ...$fields))));
        Assert::same(
            actual: rejection(
                static fn() => RequestHead::parse(head('GET / HTTP/1.0', 'Host: example.com', ...$fields)),
            ),
            expected: '431: A message must have at most 100 fields, got 101.',
        );
    }

    #[DataProvider('malformedRequestLines')]
    #[DataProvider('malformedFields')]
    #[DataProvider('ambiguousFramings')]
    #[DataProvider('invalidHosts')]
    #[DataProvider('invalidTargets')]
    public function rejects(string $bytes, string $rejection): void
    {
        Assert::same(rejection(static fn() => RequestHead::parse($bytes)), $rejection);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedRequestLines(): iterable
    {
        $line = '400: A request line must be a method, a target and a version with a space between them, got %s.';
        $version = '400: The version of a request must be HTTP/1.0 or HTTP/1.1, got %s.';
        $unsupported = '505: The version of a request must be HTTP/1.0 or HTTP/1.1, got %s.';

        yield 'nothing' => ['', \sprintf($line, '""')];
        yield 'empty line before' => [head('', 'GET / HTTP/1.1', 'Host: a'), \sprintf($line, '""')];
        yield 'no version' => [head('GET /', 'Host: a'), \sprintf($line, '"GET /"')];
        yield 'two spaces' => [head('GET  / HTTP/1.1', 'Host: a'), \sprintf($line, '"GET  / HTTP/1.1"')];
        yield 'trailing space' => [head('GET / HTTP/1.1 ', 'Host: a'), \sprintf($line, '"GET / HTTP/1.1 "')];
        yield 'leading space' => [head(' GET / HTTP/1.1', 'Host: a'), \sprintf($line, '" GET / HTTP/1.1"')];
        yield 'space in target' => [head('GET /a b HTTP/1.1', 'Host: a'), \sprintf($line, '"GET /a b HTTP/1.1"')];
        yield 'tabs' => [head("GET\t/\tHTTP/1.1", 'Host: a'), \sprintf($line, '"GET\t/\tHTTP/1.1"')];
        yield 'long line' => [
            head('GET /' . str_repeat('a', 100)),
            \sprintf($line, '"GET /' . str_repeat('a', 59) . '..."'),
        ];
        yield 'method with quote' => [
            head('G"ET / HTTP/1.1', 'Host: a'),
            '400: A method must be a token, got "G\"ET".',
        ];
        yield 'empty method' => [head(' / HTTP/1.1', 'Host: a'), '400: A method must be a token, got "".'];
        yield 'bare LF' => ["GET / HTTP/1.1\nHost: a", \sprintf($line, '"GET / HTTP/1.1\nHost: a"')];
        yield 'bare LF after version' => ["GET / HTTP/1.1\n", \sprintf($version, '"HTTP/1.1\n"')];
        yield 'bare CR after version' => ["GET / HTTP/1.1\r", \sprintf($version, '"HTTP/1.1\r"')];
        yield 'lowercase version' => [head('GET / http/1.1', 'Host: a'), \sprintf($version, '"http/1.1"')];
        yield 'HTTP/1.2' => [head('GET / HTTP/1.2', 'Host: a'), \sprintf($unsupported, '"HTTP/1.2"')];
        yield 'HTTP/2.0' => [head('PRI * HTTP/2.0'), \sprintf($unsupported, '"HTTP/2.0"')];
        yield 'HTTP/0.9' => [head('GET / HTTP/0.9'), \sprintf($unsupported, '"HTTP/0.9"')];
        yield 'version with LF' => ["GET / HTTP/2.0\n", \sprintf($version, '"HTTP/2.0\n"')];
        yield 'version after bytes' => [head('GET / xHTTP/2.0'), \sprintf($version, '"xHTTP/2.0"')];
        yield 'other protocol' => [head('GET / RTSP/1.0'), \sprintf($version, '"RTSP/1.0"')];
        yield 'HTTP/1' => [head('GET / HTTP/1', 'Host: a'), \sprintf($version, '"HTTP/1"')];
        yield 'HTTP/1.10' => [head('GET / HTTP/1.10', 'Host: a'), \sprintf($version, '"HTTP/1.10"')];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedFields(): iterable
    {
        $line = '400: A field line must be a token, a colon and a value, got %s.';
        $value = '400: A value of the field "X" must have no control characters, got %s.';
        $request = self::get(...);

        yield 'no colon' => [$request('X'), \sprintf($line, '"X"')];
        yield 'empty name' => [$request(': value'), \sprintf($line, '": value"')];
        yield 'space before colon' => [$request('X : value'), \sprintf($line, '"X : value"')];
        yield 'tab before colon' => [$request("X\t: value"), \sprintf($line, '"X\t: value"')];
        yield 'folded line' => [$request('X: a', ' b'), \sprintf($line, '" b"')];
        yield 'line folded by tab' => [$request('X: a', "\tb: c"), \sprintf($line, '"\tb: c"')];
        yield 'name above ASCII' => [$request("X\xC3\xA9: a"), \sprintf($line, '"X\303\251: a"')];
        yield 'empty line' => [$request('X: a', '', 'Y: b'), \sprintf($line, '""')];
        yield 'empty line at end' => [$request('X: a', ''), \sprintf($line, '""')];
        yield 'bare LF between fields' => [$request("X: a\nY: b"), \sprintf($value, '"a\nY: b"')];
        yield 'bare CR in value' => [$request("X: a\rb"), \sprintf($value, '"a\rb"')];
        yield 'NUL in value' => [$request("X: a\0b"), \sprintf($value, '"a\000b"')];
        yield 'DEL in value' => [$request("X: a\x7F"), \sprintf($value, '"a\177"')];
        yield 'invalid upgrade protocol' => [
            $request('Connection: upgrade', 'Upgrade: example/1/2'),
            '400: An Upgrade protocol must be a token with an optional token version, got "example/1/2".',
        ];
        yield 'option of connection with space' => [
            $request('Connection: keep alive'),
            '400: A Connection option must be a token, got "keep alive".',
        ];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function ambiguousFramings(): iterable
    {
        $length = '400: Content-Length must be one number of at most 18 digits, got %s.';
        $coding = '501: Transfer-Encoding must be "chunked", got %s.';
        $request = self::post(...);

        yield 'length and chunks' => [
            $request('Content-Length: 5', 'Transfer-Encoding: chunked'),
            '400: A message must not have both Content-Length and Transfer-Encoding.',
        ];
        yield 'chunks and length' => [
            $request('Transfer-Encoding: chunked', 'Content-Length: 0'),
            '400: A message must not have both Content-Length and Transfer-Encoding.',
        ];
        yield 'two lengths' => [$request('Content-Length: 5', 'Content-Length: 6'), \sprintf($length, '"5, 6"')];
        yield 'same length twice' => [$request('Content-Length: 5', 'content-length: 5'), \sprintf($length, '"5, 5"')];
        yield 'list of lengths' => [$request('Content-Length: 5, 5'), \sprintf($length, '"5, 5"')];
        yield 'empty length' => [$request('Content-Length:'), \sprintf($length, '""')];
        yield 'plus' => [$request('Content-Length: +5'), \sprintf($length, '"+5"')];
        yield 'minus' => [$request('Content-Length: -5'), \sprintf($length, '"-5"')];
        yield 'hexadecimal' => [$request('Content-Length: 0x5'), \sprintf($length, '"0x5"')];
        yield 'fraction' => [$request('Content-Length: 5.0'), \sprintf($length, '"5.0"')];
        yield 'exponent' => [$request('Content-Length: 1e3'), \sprintf($length, '"1e3"')];
        yield 'space inside' => [$request('Content-Length: 5 5'), \sprintf($length, '"5 5"')];
        yield 'letter after' => [$request('Content-Length: 5a'), \sprintf($length, '"5a"')];
        yield 'nineteen digits' => [
            $request('Content-Length: 1000000000000000000'),
            \sprintf($length, '"1000000000000000000"'),
        ];
        yield 'other coding' => [$request('Transfer-Encoding: gzip'), \sprintf($coding, '"gzip"')];
        yield 'coding before chunked' => [
            $request('Transfer-Encoding: gzip, chunked'),
            \sprintf($coding, '"gzip, chunked"'),
        ];
        yield 'chunked twice' => [
            $request('Transfer-Encoding: chunked, chunked'),
            \sprintf($coding, '"chunked, chunked"'),
        ];
        yield 'chunked on two lines' => [
            $request('Transfer-Encoding: chunked', 'Transfer-Encoding: chunked'),
            \sprintf($coding, '"chunked, chunked"'),
        ];
        yield 'chunked with space inside' => [$request('Transfer-Encoding: chun ked'), \sprintf($coding, '"chun ked"')];
        yield 'chunked with tab before' => [$request("Transfer-Encoding: \x0Bchunked"), \sprintf(
            '400: A value of the field "Transfer-Encoding" must have no control characters, got %s.',
            '"\vchunked"',
        )];
        yield 'empty coding' => [$request('Transfer-Encoding:'), \sprintf($coding, '""')];
        yield 'chunked in HTTP/1.0' => [
            head('POST / HTTP/1.0', 'Transfer-Encoding: chunked'),
            '400: An HTTP/1.0 message must not have Transfer-Encoding.',
        ];
        yield 'other coding in HTTP/1.0' => [
            head('POST / HTTP/1.0', 'Transfer-Encoding: gzip'),
            '400: An HTTP/1.0 message must not have Transfer-Encoding.',
        ];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidHosts(): iterable
    {
        $host = '400: Host must be a host and an optional port, got %s.';
        $request = self::withoutHost(...);

        yield 'no host in HTTP/1.1' => [$request(), '400: An HTTP/1.1 request must have one Host, got 0.'];
        yield 'two hosts' => [$request('Host: a', 'Host: a'), '400: An HTTP/1.1 request must have one Host, got 2.'];
        yield 'two hosts in HTTP/1.0' => [
            head('GET / HTTP/1.0', 'Host: a', 'host: b'),
            '400: An HTTP/1.1 request must have one Host, got 2.',
        ];
        yield 'empty host' => [$request('Host:'), \sprintf($host, '""')];
        yield 'empty host in HTTP/1.0' => [head('GET / HTTP/1.0', 'Host:'), \sprintf($host, '""')];
        yield 'port only' => [$request('Host: :80'), \sprintf($host, '":80"')];
        yield 'user' => [$request('Host: user@example.com'), \sprintf($host, '"user@example.com"')];
        yield 'path' => [$request('Host: example.com/evil'), \sprintf($host, '"example.com/evil"')];
        yield 'query' => [$request('Host: example.com?evil'), \sprintf($host, '"example.com?evil"')];
        yield 'fragment' => [$request('Host: example.com#evil'), \sprintf($host, '"example.com#evil"')];
        yield 'space' => [$request('Host: example.com evil.example'), \sprintf($host, '"example.com evil.example"')];
        yield 'port that is not number' => [$request('Host: example.com:http'), \sprintf($host, '"example.com:http"')];
        yield 'port above greatest' => [$request('Host: example.com:65536'), \sprintf($host, '"example.com:65536"')];
        yield 'two ports' => [$request('Host: example.com:80:80'), \sprintf($host, '"example.com:80:80"')];
        yield 'open bracket' => [$request('Host: [::1'), \sprintf($host, '"[::1"')];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidTargets(): iterable
    {
        $path = '400: A target must be a valid RFC 3986 path with an optional query, got %s.';
        $any = '400: A target must be a path, an http or https URI without user info and a fragment, or "*" for OPTIONS, got %s.';
        $connect = '400: The target of CONNECT must be a host and a port, got %s.';
        $request = static fn(string $method, string $target) => head(
            "{$method} {$target} HTTP/1.1",
            'Host: example.com',
        );

        yield 'fragment' => [$request('GET', '/a#b'), \sprintf($path, '"/a#b"')];
        yield 'empty fragment' => [$request('GET', '/a#'), \sprintf($path, '"/a#"')];
        yield 'bytes above ASCII' => [$request('GET', "/caf\xC3\xA9"), \sprintf($path, '"/caf\303\251"')];
        yield 'quote' => [$request('GET', '/a"b'), \sprintf($path, '"/a\"b"')];
        yield 'angle brackets' => [$request('GET', '/a?<b>'), \sprintf($path, '"/a?<b>"')];
        yield 'percent without digits' => [$request('GET', '/a%zz'), \sprintf($path, '"/a%zz"')];
        yield 'NUL' => [$request('GET', "/a\0b"), \sprintf($path, '"/a\000b"')];
        yield 'two slashes without host' => [
            head('GET //evil.example/a HTTP/1.0'),
            '400: A target that starts with two slashes needs Host, got "//evil.example/a".',
        ];
        yield 'empty' => [$request('GET', ''), \sprintf($any, '""')];
        yield 'asterisk of GET' => [$request('GET', '*'), \sprintf($any, '"*"')];
        yield 'no slash' => [$request('GET', 'a/b'), \sprintf($any, '"a/b"')];
        yield 'host and port of GET' => [$request('GET', 'example.com:80'), \sprintf($any, '"example.com:80"')];
        yield 'other scheme' => [$request('GET', 'ftp://example.com/a'), \sprintf($any, '"ftp://example.com/a"')];
        yield 'user' => [$request('GET', 'http://user@example.com/a'), \sprintf($any, '"http://user@example.com/a"')];
        yield 'no host' => [$request('GET', 'http:///a'), \sprintf($any, '"http:///a"')];
        yield 'no slashes after scheme' => [$request('GET', 'http:a'), \sprintf($any, '"http:a"')];
        yield 'fragment of URI' => [
            $request('GET', 'http://example.com/a#b'),
            \sprintf($any, '"http://example.com/a#b"'),
        ];
        yield 'port of URI above greatest' => [
            $request('GET', 'http://example.com:65536/a'),
            \sprintf($any, '"http://example.com:65536/a"'),
        ];
        yield 'bracket in host of URI' => [
            $request('GET', 'http://exa[mple.com/a'),
            \sprintf($any, '"http://exa[mple.com/a"'),
        ];
        yield 'CONNECT of path' => [$request('CONNECT', '/a'), \sprintf($connect, '"/a"')];
        yield 'CONNECT without port' => [$request('CONNECT', 'example.com'), \sprintf($connect, '"example.com"')];
        yield 'CONNECT with user' => [
            $request('CONNECT', 'user@example.com:443'),
            \sprintf($connect, '"user@example.com:443"'),
        ];
        yield 'CONNECT of URI' => [
            $request('CONNECT', 'https://example.com:443'),
            \sprintf($connect, '"https://example.com:443"'),
        ];
        yield 'CONNECT of asterisk' => [$request('CONNECT', '*'), \sprintf($connect, '"*"')];
        yield 'unescaped bracket in URI' => [
            $request('GET', 'http://example.com/a?b[c]=d'),
            \sprintf($any, '"http://example.com/a?b[c]=d"'),
        ];
        yield 'target shorter than scheme separator' => [$request('GET', 'a'), \sprintf($any, '"a"')];
        yield 'CONNECT with port above greatest' => [
            $request('CONNECT', 'example.com:65536'),
            \sprintf($connect, '"example.com:65536"'),
        ];
    }

    public function answersMalformedRequestWithBadRequestByDefault(): void
    {
        Assert::same(new MalformedMessage('Malformed.')->status, 400);
    }

    /**
     * @no-named-arguments
     */
    private static function get(string ...$fields): string
    {
        return head('GET / HTTP/1.1', 'Host: a', ...$fields);
    }

    /**
     * @no-named-arguments
     */
    private static function post(string ...$fields): string
    {
        return head('POST / HTTP/1.1', 'Host: a', ...$fields);
    }

    /**
     * @no-named-arguments
     */
    private static function withoutHost(string ...$fields): string
    {
        return head('GET / HTTP/1.1', ...$fields);
    }
}
