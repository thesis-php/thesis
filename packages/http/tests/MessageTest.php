<?php

declare(strict_types=1);

namespace Thesis\Http;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Uri\Rfc3986\Uri;

#[Test]
#[Covers(Request::class)]
#[Covers(Response::class)]
#[Covers(StringBody::class)]
#[Covers(Query::class)]
final class MessageTest
{
    public function requestHasNoHeadersAndEmptyBodyByDefault(): void
    {
        $request = new Request('GET', new Uri('https://example.com/'));

        Assert::same(lines($request->headers), []);
        Assert::same(readAll($request->body), '');
    }

    public function stringBodyKeepsItsSizeAndSharesItsPositionWithTheMessageCopy(): void
    {
        $request = new Request('POST', new Uri('/'), body: "a\0b");
        $copy = clone $request;

        Assert::true($request->body->isBuffered);
        Assert::same($request->body->read(2), "a\0");
        Assert::same($copy->body->read(2), 'b');
        Assert::same($request->body->size, 3);
        Assert::null($request->body->read(1));
    }

    public function everyRequestHasBodyOfItsOwn(): void
    {
        $uri = new Uri('https://example.com/');

        Assert::notSame(new Request('GET', $uri)->body, new Request('GET', $uri)->body);
    }

    public function copiesRequestWithPropertiesChanged(): void
    {
        $request = new Request('POST', new Uri('https://example.com/a?b'), body: 'abc');

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

    public function replacingTheQueryUpdatesOnlyTheClonedUri(): void
    {
        $request = new Request('GET', new Uri('/search?tag=a&sort=C++&tag=b#top'));
        $query = $request->query;
        $next = clone($request, ['query' => $query->with('tag', 'a & b')]);
        $other = clone($next, ['uri' => new Uri('/?page=2')]);

        Assert::same($query->all('tag'), ['a', 'b']);
        Assert::same($query->get('sort'), 'C++');
        Assert::same($next->uri->toRawString(), '/search?tag=a%20%26%20b&sort=C%2B%2B#top');
        Assert::same($other->query->toArray(), ['page' => ['2']]);
        Assert::same($request->query, $query);
    }

    public function responseIsOkWithoutHeadersAndWithEmptyBodyByDefault(): void
    {
        $response = new Response();

        Assert::same($response->status, 200);
        Assert::same(lines($response->headers), []);
        Assert::same(readAll($response->body), '');
    }

    public function closingAStringBodyLeavesItsBytesAvailable(): void
    {
        $response = new Response(body: 'payload');
        $response->body->close();

        Assert::same(readAll($response->body), 'payload');
    }

    public function everyResponseHasBodyOfItsOwn(): void
    {
        Assert::notSame(new Response()->body, new Response()->body);
    }

    public function copiesResponseWithPropertiesChanged(): void
    {
        $response = new Response(headers: new Headers(['content-type' => 'text/plain']));

        $copy = clone($response, ['status' => 404, 'body' => new StringBody('Not found.')]);

        Assert::same($copy->status, 404);
        Assert::same($copy->headers, $response->headers);
        Assert::same(readAll($copy->body), 'Not found.');
        Assert::same($response->status, 200);
    }

    public function replacesTrailersOnACopyOfTheResponse(): void
    {
        $originalTrailers = new Headers(['x-original' => 'yes']);
        $response = new Response(body: new StringBody('', $originalTrailers));
        $trailers = new Headers(['x-count' => '1']);

        $copy = clone($response, ['body' => new StringBody('', $trailers)]);

        Assert::same($response->body->discard(), $originalTrailers);
        Assert::same($copy->body->discard(), $trailers);
    }

    public function discardsTheRestOfAStringBodyAndReturnsItsTrailers(): void
    {
        $trailers = new Headers(['x-count' => '1']);
        $response = new Response(body: new StringBody('payload', $trailers));
        $copy = clone $response;

        Assert::same($response->body->read(3), 'pay');
        Assert::same($response->body->discard(), $trailers);
        Assert::null($copy->body->read(1));
        Assert::same($response->body->size, 7);
    }
}
