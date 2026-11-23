<?php

declare(strict_types=1);

namespace Thesis\Http;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Thesis\Http\Http1\ChunkedReader;
use Thesis\Io\Buffer;
use Thesis\Io\ReadFailed;
use Thesis\Io\StringReader;
use Uri\Rfc3986\Uri;

#[Test]
#[Covers(Request::class)]
#[Covers(Response::class)]
final class MessageTest
{
    public function requestHasNoHeadersAndEmptyBodyByDefault(): void
    {
        $request = new Request('GET', new Uri('https://example.com/'));

        Assert::same(lines($request->headers), []);
        Assert::same(readAll($request->body), '');
    }

    public function everyRequestHasBodyOfItsOwn(): void
    {
        $uri = new Uri('https://example.com/');

        Assert::notSame(new Request('GET', $uri)->body, new Request('GET', $uri)->body);
    }

    public function copiesRequestWithPropertiesChanged(): void
    {
        $request = new Request('POST', new Uri('https://example.com/a?b'), body: new StringReader('abc'));

        $copy = clone($request, [
            'method' => 'PUT',
            'uri' => $request->uri->withHost('backend'),
            'headers' => new Headers(['x-forwarded-host' => 'example.com']),
        ]);

        Assert::same($copy->method, 'PUT');
        Assert::same($copy->uri->toRawString(), 'https://backend/a?b');
        Assert::same(lines($copy->headers), [['x-forwarded-host', 'example.com']]);
        Assert::same($copy->body, $request->body);
        Assert::same($request->method, 'POST');
    }

    public function responseIsOkWithoutHeadersAndWithEmptyBodyByDefault(): void
    {
        $response = new Response();

        Assert::same($response->status, 200);
        Assert::same(lines($response->headers), []);
        Assert::same(readAll($response->body), '');
    }

    public function everyResponseHasBodyOfItsOwn(): void
    {
        Assert::notSame(new Response()->body, new Response()->body);
    }

    public function copiesResponseWithPropertiesChanged(): void
    {
        $response = new Response(headers: new Headers(['content-type' => 'text/plain']));

        $copy = clone($response, ['status' => 404, 'body' => new StringReader('Not found.')]);

        Assert::same($copy->status, 404);
        Assert::same($copy->headers, $response->headers);
        Assert::same(readAll($copy->body), 'Not found.');
        Assert::same($response->status, 200);
    }

    public function replacesTrailersOnACopyOfTheResponse(): void
    {
        $response = new Response(trailers: null);
        $trailers = new Headers(['x-count' => '1']);

        $copy = clone($response, ['trailers' => $trailers]);

        Assert::null($response->trailers);
        Assert::same($copy->trailers, $trailers);
    }

    public function discardsRemainingRequestBodyThroughItsTrailers(): void
    {
        $buffer = new Buffer(bytes: "3\r\nabc\r\n0\r\nX-End: yes\r\n\r\nnext");
        $body = new ChunkedReader($buffer, new StringReader(''));
        $request = new Request('POST', new Uri('/'), body: $body, trailers: static fn() => $body->trailers);

        Assert::same($request->body->read(1), 'a');
        Assert::null($request->trailers);
        Assert::same($request->discardBodyAndReadTrailers()->get('x-end'), 'yes');
        Assert::same($request->trailers, $request->discardBodyAndReadTrailers());
        Assert::same($buffer->drain(), 'next');
    }

    public function discardsResponseBodyEvenWhenTrailersAreReady(): void
    {
        $response = new Response(body: new StringReader('payload'));

        Assert::same($response->discardBodyAndReadTrailers(), $response->trailers);
        Assert::same(readAll($response->body), '');
    }

    public function truncatedBodyFailsWhileDiscarding(): void
    {
        $response = new Response(body: chunked("3\r\nab"));
        Expect::exception(ReadFailed::class);
        $response->discardBodyAndReadTrailers();
    }

    public function pendingTrailersAtEndOfBodyAreAnError(): void
    {
        $response = new Response(trailers: null);
        Expect::exception(\LogicException::class);
        $response->discardBodyAndReadTrailers();
    }
}
