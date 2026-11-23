<?php

declare(strict_types=1);

namespace Thesis\Http\Http1;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;
use Thesis\Http\Headers;
use Thesis\Http\Internal\Http1\Fields;
use Thesis\Http\Request;
use Thesis\Http\ScriptedReader;
use Thesis\Io\Reader;
use Thesis\Io\SizedReader;
use Thesis\Io\StringReader;
use Uri\Rfc3986\Uri;
use function Thesis\Http\failure;
use function Thesis\Http\head;
use function Thesis\Http\lines;

#[Test]
#[Covers(RequestHead::class)]
#[Covers(Fields::class)]
final class RequestHeadFormatTest
{
    public function writesRequestWithoutBody(): void
    {
        $head = RequestHead::of(new Request(
            method: 'GET',
            uri: new Uri('https://example.com/users/42?fields=name'),
            headers: new Headers(['accept' => ['text/html', 'text/plain'], 'x-request-id' => 'abc', 'etag' => '"1"']),
        ));

        Assert::same($head->version, Version::Http11);
        Assert::same($head->framing, Framing::None);
        Assert::same($head->length, 0);
        Assert::true($head->isPersistent);
        Assert::same($head->upgrade, []);
        Assert::same($head->toString(), head(
            'GET /users/42?fields=name HTTP/1.1',
            'Host: example.com',
            'Accept: text/html',
            'Accept: text/plain',
            'X-Request-Id: abc',
            'Etag: "1"',
            '',
            '',
        ));
    }

    /**
     * @param non-empty-string $method
     */
    #[DataProvider('targets')]
    public function writesTargetAndHostOfUri(string $method, string $uri, string $target, string $host): void
    {
        $head = RequestHead::of(new Request($method, new Uri($uri)));

        Assert::same($head->toString(), head("{$method} {$target} HTTP/1.1", "Host: {$host}", '', ''));
    }

    /**
     * @return iterable<string, array{non-empty-string, string, string, string}>
     */
    public static function targets(): iterable
    {
        yield 'path' => ['GET', 'http://example.com/a/b', '/a/b', 'example.com'];
        yield 'port' => ['GET', 'http://example.com:8080/a', '/a', 'example.com:8080'];
        yield 'default port given' => ['GET', 'http://example.com:80/a', '/a', 'example.com:80'];
        yield 'IPv6' => ['GET', 'http://[2001:db8::1]:8080/a', '/a', '[2001:db8::1]:8080'];
        yield 'empty path' => ['GET', 'http://example.com', '/', 'example.com'];
        yield 'empty path with query' => ['GET', 'http://example.com?a=b', '/?a=b', 'example.com'];
        yield 'empty query' => ['GET', 'http://example.com/a?', '/a?', 'example.com'];
        yield 'target as it is' => ['GET', 'http://example.com/a/../%7Eb?c=%41', '/a/../%7Eb?c=%41', 'example.com'];
        yield 'no user' => ['GET', 'http://user:secret@example.com/a', '/a', 'example.com'];
        yield 'no fragment' => ['GET', 'http://example.com/a?b#c', '/a?b', 'example.com'];
        yield 'host as it is' => ['GET', 'http://EXAMPLE.com/a', '/a', 'EXAMPLE.com'];
        yield 'OPTIONS of server' => ['OPTIONS', 'http://example.com', '*', 'example.com'];
        yield 'OPTIONS of path' => ['OPTIONS', 'http://example.com/', '/', 'example.com'];
        yield 'OPTIONS of query' => ['OPTIONS', 'http://example.com?a', '/?a', 'example.com'];
        yield 'CONNECT' => ['CONNECT', '//upstream.example:8443', 'upstream.example:8443', 'upstream.example:8443'];
        yield 'CONNECT of https' => ['CONNECT', 'https://upstream.example', 'upstream.example:443', 'upstream.example'];
        yield 'CONNECT of http' => [
            'CONNECT',
            'http://upstream.example/a?b',
            'upstream.example:80',
            'upstream.example',
        ];
        yield 'CONNECT of HTTP' => ['CONNECT', 'HTTP://upstream.example', 'upstream.example:80', 'upstream.example'];
        yield 'CONNECT of https with port' => [
            'CONNECT',
            'https://upstream.example:8443',
            'upstream.example:8443',
            'upstream.example:8443',
        ];
        yield 'CONNECT of IPv6' => ['CONNECT', '//[::1]:8443', '[::1]:8443', '[::1]:8443'];
    }

    /**
     * @param non-empty-string $method
     */
    #[DataProvider('bodies')]
    public function framesBodyByItsLength(string $method, Reader $reader, Framing $framing, ?string $field): void
    {
        $head = RequestHead::of(new Request($method, new Uri('http://example.com/'), body: $reader));

        Assert::same($head->framing, $framing);
        Assert::same($head->length, $reader instanceof SizedReader ? $reader->size : null);
        $fields = [...(array) $field, '', ''];
        Assert::same($head->toString(), head("{$method} / HTTP/1.1", 'Host: example.com', ...$fields));
    }

    /**
     * @return iterable<string, array{non-empty-string, Reader, Framing, ?string}>
     */
    public static function bodies(): iterable
    {
        yield 'GET without body' => ['GET', new StringReader(''), Framing::None, null];
        yield 'DELETE without body' => ['DELETE', new StringReader(''), Framing::None, null];
        yield 'POST without body' => ['POST', new StringReader(''), Framing::Length, 'Content-Length: 0'];
        yield 'PUT without body' => ['PUT', new StringReader(''), Framing::Length, 'Content-Length: 0'];
        yield 'PATCH without body' => ['PATCH', new StringReader(''), Framing::Length, 'Content-Length: 0'];
        yield 'POST of string' => ['POST', new StringReader('hello'), Framing::Length, 'Content-Length: 5'];
        yield 'GET of string' => ['GET', new StringReader('a'), Framing::Length, 'Content-Length: 1'];
        yield 'POST of unknown length' => [
            'POST',
            new ScriptedReader('abc'),
            Framing::Chunked,
            'Transfer-Encoding: chunked',
        ];
        yield 'GET of unknown length' => [
            'GET',
            new ScriptedReader('abc'),
            Framing::Chunked,
            'Transfer-Encoding: chunked',
        ];
    }

    public function asksToCloseConnection(): void
    {
        $head = RequestHead::of(new Request('GET', new Uri('http://example.com/')), isPersistent: false);

        Assert::false($head->isPersistent);
        Assert::same($head->toString(), head('GET / HTTP/1.1', 'Host: example.com', 'Connection: close', '', ''));
    }

    public function usesChunkedFramingForKnownLengthWithTrailers(): void
    {
        $request = new Request(
            method: 'POST',
            uri: new Uri('http://example.com/'),
            body: new StringReader('abc'),
            trailers: new Headers(['x-count' => '1']),
        );

        $head = RequestHead::of($request);

        Assert::same($head->framing, Framing::Chunked);
        Assert::null($head->length);
        Assert::same(
            actual: $head->toString(),
            expected: head('POST / HTTP/1.1', 'Host: example.com', 'Transfer-Encoding: chunked', '', ''),
        );
    }

    public function rejectsMalformedUpgradeName(): void
    {
        $request = new Request('GET', new Uri('http://example.com/'));

        Assert::same(
            actual: failure(static fn() => RequestHead::of($request, upgrade: ['two words'])),
            expected: 'An Upgrade protocol must be a token with an optional token version, got "two words".',
        );
    }

    public function offersProtocolsToSwitchTo(): void
    {
        $head = RequestHead::of(new Request('GET', new Uri('http://example.com/chat')), upgrade: ['websocket', 'h2c']);

        Assert::same($head->upgrade, ['websocket', 'h2c']);
        Assert::same($head->toString(), head(
            'GET /chat HTTP/1.1',
            'Host: example.com',
            'Upgrade: websocket, h2c',
            'Connection: upgrade',
            '',
            '',
        ));
    }

    public function writesFieldsOfConnectionAfterHeaders(): void
    {
        $head = RequestHead::of(
            request: new Request(
                method: 'POST',
                uri: new Uri('http://example.com/'),
                headers: new Headers(['content-type' => 'text/plain']),
                body: new StringReader('abc'),
            ),
            isPersistent: false,
            upgrade: ['websocket'],
        );

        Assert::same($head->toString(), head(
            'POST / HTTP/1.1',
            'Host: example.com',
            'Content-Type: text/plain',
            'Content-Length: 3',
            'Upgrade: websocket',
            'Connection: close, upgrade',
            '',
            '',
        ));
    }

    #[DataProvider('invalidRequests')]
    public function refusesRequestItCannotWrite(Request $request, string $message): void
    {
        Assert::same(failure(static fn() => RequestHead::of($request)), $message);
    }

    /**
     * @return iterable<string, array{Request, string}>
     */
    public static function invalidRequests(): iterable
    {
        $uri = new Uri('http://example.com/');
        $connection = 'The header "%s" belongs to an HTTP/1.1 connection and is written by the codec.';

        yield 'method with line' => [
            new Request("GET / HTTP/1.1\r\nX-Injected: 1\r\n\r\nGET", $uri),
            'A method must be a token, got "GET / HTTP/1.1\r\nX-Injected: 1\r\n\r\nGET".',
        ];
        yield 'method with space' => [new Request('GET /', $uri), 'A method must be a token, got "GET /".'];
        yield 'no host' => [new Request('GET', new Uri('/a')), 'The URI of a request must have a host, got "/a".'];
        yield 'empty host' => [
            new Request('GET', new Uri('http:///a')),
            'The URI of a request must have a host, got "http:///a".',
        ];
        yield 'port out of range' => [
            new Request('GET', new Uri('http://example.com:65536')),
            'A request port must be at most 65535, got 65536.',
        ];
        yield 'CONNECT without port' => [
            new Request('CONNECT', new Uri('//example.com')),
            'The URI of CONNECT must have a port or the scheme http or https, got "//example.com".',
        ];
        yield 'CONNECT of other scheme' => [
            new Request('CONNECT', new Uri('ftp://example.com')),
            'The URI of CONNECT must have a port or the scheme http or https, got "ftp://example.com".',
        ];

        foreach (['content-length', 'transfer-encoding', 'connection', 'keep-alive', 'upgrade'] as $name) {
            yield $name => [new Request('GET', $uri, new Headers([$name => 'x'])), \sprintf($connection, $name)];
        }

        yield 'host' => [
            new Request('GET', $uri, new Headers(['Host' => 'evil.example'])),
            'The header "host" is written by the codec from the URI of a request.',
        ];
    }

    #[DataProvider('heads')]
    public function writesHeadItHasParsed(string $bytes): void
    {
        Assert::same(RequestHead::parse($bytes)->toString(), $bytes . "\r\n\r\n");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function heads(): iterable
    {
        yield 'GET' => [head('GET /a?b HTTP/1.1', 'Host: example.com:8080', 'Accept: */*')];
        yield 'length' => [head('POST / HTTP/1.1', 'Host: example.com', 'Content-Length: 0')];
        yield 'chunks' => [head('POST / HTTP/1.1', 'Host: example.com', 'Transfer-Encoding: chunked')];
        yield 'close' => [head('GET / HTTP/1.1', 'Host: example.com', 'Connection: close')];
        yield 'upgrade' => [head('GET / HTTP/1.1', 'Host: example.com', 'Upgrade: websocket', 'Connection: upgrade')];
        yield 'OPTIONS of server' => [head('OPTIONS * HTTP/1.1', 'Host: example.com')];
        yield 'CONNECT' => [head('CONNECT example.com:443 HTTP/1.1', 'Host: example.com:443')];
        yield 'HTTP/1.0 without host' => [head('GET /a HTTP/1.0')];
        yield 'HTTP/1.0 without host of server' => [head('OPTIONS * HTTP/1.0')];
        yield 'HTTP/1.0 that stays open' => [head('GET /a HTTP/1.0', 'Host: example.com', 'Connection: keep-alive')];
    }

    public function parsesHeadItHasWritten(): void
    {
        $request = new Request(
            method: 'PUT',
            uri: new Uri('https://example.com:8443/files/a%20b?overwrite=1'),
            headers: new Headers(['content-type' => 'text/plain', 'accept' => ['a/b', 'c/d']]),
            body: new StringReader('hello'),
        );
        $written = RequestHead::of($request, isPersistent: false, upgrade: ['h2c']);

        $parsed = RequestHead::parse(substr($written->toString(), 0, -4), scheme: 'https');

        Assert::same($parsed->method, 'PUT');
        Assert::same($parsed->uri->toRawString(), 'https://example.com:8443/files/a%20b?overwrite=1');
        Assert::same(lines($parsed->headers), lines($request->headers));
        Assert::same($parsed->version, Version::Http11);
        Assert::same($parsed->framing, Framing::Length);
        Assert::same($parsed->length, 5);
        Assert::false($parsed->isPersistent);
        Assert::same($parsed->upgrade, ['h2c']);
    }
}
