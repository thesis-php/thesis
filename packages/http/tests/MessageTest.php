<?php

declare(strict_types=1);

namespace Thesis\Http;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
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
        Assert::same(readAll($request->reader), '');
    }

    public function everyRequestHasBodyOfItsOwn(): void
    {
        $uri = new Uri('https://example.com/');

        Assert::notSame(new Request('GET', $uri)->reader, new Request('GET', $uri)->reader);
    }

    public function copiesRequestWithPropertiesChanged(): void
    {
        $request = new Request('POST', new Uri('https://example.com/a?b'), reader: new StringReader('abc'));

        $copy = clone($request, [
            'method' => 'PUT',
            'uri' => $request->uri->withHost('backend'),
            'headers' => new Headers(['x-forwarded-host' => 'example.com']),
        ]);

        Assert::same($copy->method, 'PUT');
        Assert::same($copy->uri->toRawString(), 'https://backend/a?b');
        Assert::same(lines($copy->headers), [['x-forwarded-host', 'example.com']]);
        Assert::same($copy->reader, $request->reader);
        Assert::same($request->method, 'POST');
    }

    public function responseIsOkWithoutHeadersAndWithEmptyBodyByDefault(): void
    {
        $response = new Response();

        Assert::same($response->status, 200);
        Assert::same(lines($response->headers), []);
        Assert::same(readAll($response->reader), '');
    }

    public function everyResponseHasBodyOfItsOwn(): void
    {
        Assert::notSame(new Response()->reader, new Response()->reader);
    }

    public function copiesResponseWithPropertiesChanged(): void
    {
        $response = new Response(headers: new Headers(['content-type' => 'text/plain']));

        $copy = clone($response, ['status' => 404, 'reader' => new StringReader('Not found.')]);

        Assert::same($copy->status, 404);
        Assert::same($copy->headers, $response->headers);
        Assert::same(readAll($copy->reader), 'Not found.');
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
}
