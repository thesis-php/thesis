<?php

declare(strict_types=1);

namespace Thesis\Http;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Uri\Rfc3986\Uri;

#[Test]
#[Covers(Message::class)]
#[Covers(Request::class)]
#[Covers(Response::class)]
#[Covers(Query::class)]
#[Covers(StringBody::class)]
final class MessageTest
{
    public function acceptsHeaderArraysForMessagesAndTrailers(): void
    {
        $fields = ['Content-Type' => 'text/custom', 'X-Test' => ['a', 'b']];
        foreach ([
            new Request('GET', '/', $fields),
            new Response(headers: $fields),
            new Response()->withHeaders($fields),
        ] as $message) {
            Assert::same($message->headers->get('content-type'), 'text/custom');
            Assert::same($message->headers->all('x-test'), ['a', 'b']);
        }

        Assert::same(new StringBody('', $fields)->trailers()->all('x-test'), ['a', 'b']);
    }

    public function replacesRawAndDecodedQueriesAndAppendsInOrder(): void
    {
        $request = new Request('GET', '/old')->withQuery(['tag' => ['a', 'b'], 'q' => 'a+b']);
        Assert::same($request->uri->getRawQuery(), 'tag=a&tag=b&q=a%2Bb');
        $moved = $request->withUri('/new?q=old#end');
        Assert::same($moved->query->get('q'), 'old');
        $raw = $moved->withQuery('flag&tag=%61&Tag=x&tag=b');
        Assert::same($raw->uri->toRawString(), '/new?flag&tag=%61&Tag=x&tag=b#end');
        Assert::same($raw->query->get('flag'), '');
        $added = $raw->withAddedQueryParameter('tag', ['c', 'd']);
        Assert::same($added->uri->getRawQuery(), 'flag&tag=%61&Tag=x&tag=b&tag=c&tag=d');
        Assert::same($added->withoutQueryParameter('tag')->uri->getRawQuery(), 'flag=&Tag=x');
        Assert::same($added->query->without('tag', 'flag')->toString(), 'Tag=x');
        Assert::same($raw->query->all('tag'), ['a', 'b']);
    }

    public function jsonResponseEncodesValuesAndSetsTheMediaType(): void
    {
        $response = Response::json(['id' => 42], status: Status::CREATED);
        Assert::same($response->status, Status::CREATED);
        Assert::same($response->headers->get('content-type'), 'application/json');
        Assert::same($response->takeBody()->readAll(), '{"id":42}');
        Expect::exception(\JsonException::class);
        Response::json(NAN);
    }

    public function htmlResponseKeepsTheSuppliedBody(): void
    {
        $body = new StringBody('<p>Hello</p>');
        $response = Response::html($body);
        Assert::same($response->status, Status::OK);
        Assert::same($response->headers->get('content-type'), 'text/html; charset=utf-8');
        Assert::same($response->takeBody(), $body);
    }

    public function factoryResponsesShareHeadersAndAppendFieldLines(): void
    {
        Assert::same(Response::html('a')->headers, Response::html('b')->headers);
        $json = Response::json(null);
        $copy = $json->withAddedHeaders(['X-Tag' => ['a', 'b'], 123 => 'first'])
            ->withAddedHeaders(new Headers(['x-tag' => 'c', 123 => 'second']))
        ;
        Assert::same($copy->headers->all('content-type'), ['application/json']);
        Assert::same($copy->headers->all('x-tag'), ['a', 'b', 'c']);
        Assert::same($copy->headers->all('123'), ['first', 'second']);
        Assert::false($json->headers->has('x-tag'));
        Assert::same($json->headers, Response::json(1)->headers);
    }

    public function appendsOnlyNewQueryBytes(): void
    {
        $request = new Request('GET', '/path?#fragment');
        Assert::same($request->withAddedQuery([])->uri->toRawString(), '/path?#fragment');
        $copy = $request->withAddedQuery('flag&tag=%61')
            ->withAddedQuery(['tag' => 'a+b'])
            ->withAddedQuery(new Query(['tag' => 'c d']))
            ->withAddedQueryParameter('tag', [])
        ;
        Assert::same($copy->uri->toRawString(), '/path?flag&tag=%61&tag=a%2Bb&tag=c%20d#fragment');
        Assert::same($copy->query->all('tag'), ['a', 'a+b', 'c d']);
    }

    public function replacesTheBodyAndMediaTypeWithJson(): void
    {
        foreach ([new Request('POST', '/'), new Response(Status::CREATED)] as $message) {
            $original = $message->withHeaders(['Content-Type' => 'text/plain', 'X-Id' => '42'])->withBody('old');
            $copy = $original->withJson(['id' => 42]);
            Assert::same($copy->headers->all('content-type'), ['application/json']);
            Assert::same($copy->headers->get('x-id'), '42');
            Assert::same($copy->takeJson(), ['id' => 42]);
            Assert::same($original->headers->get('content-type'), 'text/plain');
            Assert::instanceOf(thrown(static fn() => $original->withJson(NAN)), \JsonException::class);
            Assert::same($original->takeBody()->readAll(), 'old');
        }
    }

    public function encodesJsonWithTheSuppliedFlagsAndDepth(): void
    {
        $message = new Response();
        Assert::same($message->withJson([NAN], JSON_PARTIAL_OUTPUT_ON_ERROR)->takeBody()->readAll(), '[0]');
        $copy = $message->withJson('/path', JSON_UNESCAPED_SLASHES, 1);
        Assert::same($copy->takeBody()->readAll(), '"/path"');
        Expect::exception(\JsonException::class);
        $message->withJson(value: [['nested']], flags: 0, depth: 1);
    }

    public function readsJsonObjectsAsObjectsOrAssociativeArrays(): void
    {
        $request = getRequest('/');
        Assert::same($request->withBody('{"id":42}')->takeJson(), ['id' => 42]);
        Assert::instanceOf($request->withBody('{}')->takeJson(associative: false), \stdClass::class);
        Assert::null($request->withBody('null')->takeJson(limit: 4));
        $large = '123456789012345678901234567890';
        Assert::same($request->withBody($large)->takeJson(flags: JSON_BIGINT_AS_STRING), $large);
        Expect::exception(\JsonException::class);
        $request->takeJson();
    }

    public function invalidJsonConsumesTheSharedHandoff(): void
    {
        $request = getRequest('/')->withBody('{');
        $copy = $request->withHeader('accept', 'application/json');
        Assert::instanceOf(thrown(static fn() => $request->takeJson(flags: 0)), \JsonException::class);
        Expect::exception(\LogicException::class);
        $copy->takeBody();
    }

    public function responseCopiesShareTheJsonBodyHandoff(): void
    {
        $response = new Response(body: '{"id":42}');
        $copy = $response->withStatus(Status::CREATED);
        Assert::same($copy->takeJson(), ['id' => 42]);
        Expect::exception(\LogicException::class);
        $response->takeBody();
    }

    public function boundsJsonBeforeDecoding(): void
    {
        Expect::exception(\OverflowException::class);
        getRequest('/')->withBody('null')->takeJson(limit: 3);
    }

    public function changingQueryAndHeadersLeavesTheOriginalRequestIntact(): void
    {
        $original = getRequest('/search?tag=a&sort=C++&tag=b#top');
        $copy = $original->withQueryParameter('tag', 'a & b')->withHeader('accept', 'text/plain');
        Assert::same($original->query->all('tag'), ['a', 'b']);
        Assert::same($original->query->get('sort'), 'C++');
        Assert::true($original->headers->isEmpty);
        Assert::same($copy->uri->toRawString(), '/search?tag=a%20%26%20b&sort=C%2B%2B#top');
        Assert::same($copy->withUri(new Uri('/?page=2'))->query->get('page'), '2');
    }

    public function requestCopiesShareOneHandoffAndCanOpenANewAttempt(): void
    {
        $trailers = new Headers(['x-end' => 'yes']);
        $upload = new StringBody('payload', $trailers);
        $original = new Request('PUT', new Uri('/first'), body: $upload);
        $redirect = $original->withUri(new Uri('/next'))->withQueryParameter('page', '2');
        Assert::same($redirect->takeBody(), $upload);
        Assert::same($upload->readAll(), 'payload');
        $retry = $redirect->withBody($upload->fresh());
        Assert::same($retry->uri->getRawQuery(), 'page=2');
        $body = $retry->takeBody();
        Assert::same($body->readAll(), 'payload');
        Assert::same($body->trailers(), $trailers);

        Expect::exception(\LogicException::class);
        $original->takeBody();
    }

    public function outgoingCopiesShareTheBodyAndReplaceTrailersWithIt(): void
    {
        $trailers = new Headers(['x-count' => '1']);
        $body = new StringBody('payload', $trailers);
        $original = new Response(body: $body);
        $copy = $original->withAddedHeader('set-cookie', 'a=1')->withAddedHeader('set-cookie', 'b=2');
        $taken = $copy->takeBody();
        Assert::same($taken, $body);
        Assert::same($taken->trailers(), $trailers);
        Assert::same($copy->headers->all('set-cookie'), ['a=1', 'b=2']);
        $replacement = $copy->withBody('new')->takeBody();
        Assert::same($replacement->readAll(), 'new');
        Assert::same($replacement->trailers(), EMPTY_HEADERS);

        Expect::exception(\LogicException::class);
        $original->takeBody();
    }

    public function proxyForwardsTheRequestAndReturnsTheResponseWithTheirTrailers(): void
    {
        $fields = new Headers(['x-final' => 'yes']);
        $incoming = new Request('POST', new Uri('/upload'), body: new StringBody('data', $fields));
        $send = static function (Request $forwarded) use ($fields) {
            Assert::same($forwarded->uri->getHost(), 'upstream.test');
            $upload = $forwarded->takeBody();
            Assert::same($upload->readAll(), 'data');
            Assert::same($upload->trailers(), $fields);

            return new Response(body: new StringBody('ok', $fields));
        };

        $reply = $send($incoming->withUri(new Uri('https://upstream.test/upload')));
        $body = $reply->takeBody();
        Assert::same($body->readAll(), 'ok');
        Assert::same($body->trailers(), $fields);
        Assert::null($incoming->uri->getHost());

        Expect::exception(\LogicException::class);
        $incoming->takeBody();
    }

    public function messagesShareTheEmptyReaderAndHandItOffSeparately(): void
    {
        $request = getRequest('/');
        $copy = $request->withHeader('accept', '*/*');
        Assert::same($copy->takeBody(), EMPTY_BODY);
        EMPTY_BODY->close();
        Assert::same(new Response()->takeBody(), EMPTY_BODY);
        Assert::same($request->withBody('')->takeBody(), EMPTY_BODY);
        Assert::same(EMPTY_BODY->fresh(), EMPTY_BODY);
        Assert::null(EMPTY_BODY->read(1));
        Assert::same(EMPTY_BODY->readAll(limit: 0), '');
        Assert::same(EMPTY_BODY->trailers(), EMPTY_HEADERS);

        Expect::exception(\LogicException::class);
        $request->takeBody();
    }

    public function replayPreservesTrailersEvenForAnEmptyPayload(): void
    {
        $fields = new Headers(['x-final' => 'yes']);
        $fresh = new StringBody('', $fields)->fresh();
        Assert::true($fresh->mayHaveTrailers);
        Assert::same($fresh->readAll(), '');
        Assert::same($fresh->trailers(), $fields);
        Assert::false(new StringBody('payload')->mayHaveTrailers);
    }

    public function discardConsumesTheRemainingStringAndPreservesReplay(): void
    {
        $fields = new Headers(['x-end' => 'yes']);
        $body = new StringBody('payload', $fields);
        Assert::same($body->read(1), 'p');
        Assert::same($body->discard(), $fields);
        Assert::null($body->read(1));
        Assert::same($body->fresh()->readAll(), 'payload');
    }

    public function wholeBodyReadsRejectAPartiallyConsumedUpload(): void
    {
        $body = new StringBody('12345');
        Assert::same($body->read(2), '12');
        Assert::same($body->fresh()->readAll(), '12345');
        Expect::exception(\LogicException::class);
        $body->readAll();
    }
}
