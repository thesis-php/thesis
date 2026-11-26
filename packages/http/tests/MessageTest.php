<?php

declare(strict_types=1);

namespace Thesis\Http;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
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

    public function readsRepeatedQueryParametersAndPreservesTheirOrder(): void
    {
        $request = new Request('GET', '/search?tag=%61&sort=C++&Tag=x&tag=b');
        Assert::same($request->query->all('tag'), ['a', 'b']);
        Assert::same($request->query->get('sort'), 'C++');
        $updated = $request->query->with('tag', 'a & b')->withAdded('tag', 'd')->without('sort');
        Assert::same($updated->toString(), 'tag=a%20%26%20b&Tag=x&tag=d');
        Assert::same($request->query->all('tag'), ['a', 'b']);
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

    public function replacesTheBodyAndMediaTypeWithJson(): void
    {
        $message = new Response(Status::CREATED);
        $original = $message->withHeaders(['Content-Type' => 'text/plain', 'X-Id' => '42'])->withBody('old');
        $copy = $original->withJson(['id' => 42]);
        Assert::same($copy->headers->all('content-type'), ['application/json']);
        Assert::same($copy->headers->get('x-id'), '42');
        Assert::same($copy->takeJson(), ['id' => 42]);
        Assert::same($original->headers->get('content-type'), 'text/plain');
        Assert::instanceOf(thrown(static fn() => $original->withJson(NAN)), \JsonException::class);
        Assert::same($original->takeBody()->readAll(), 'old');
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
        $response = new Response();
        Assert::same($response->withBody('{"id":42}')->takeJson(), ['id' => 42]);
        Assert::instanceOf($response->withBody('{}')->takeJson(associative: false), \stdClass::class);
        Assert::null($response->withBody('null')->takeJson(limit: 4));
        $large = '123456789012345678901234567890';
        Assert::same($response->withBody($large)->takeJson(flags: JSON_BIGINT_AS_STRING), $large);
        Expect::exception(\JsonException::class);
        $response->takeJson();
    }

    public function invalidJsonConsumesTheSharedHandoff(): void
    {
        $response = new Response()->withBody('{');
        $copy = $response->withHeader('accept', 'application/json');
        Assert::instanceOf(thrown(static fn() => $response->takeJson(flags: 0)), \JsonException::class);
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
        new Response()->withBody('null')->takeJson(limit: 3);
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

    public function serverRequestReadsJsonAndHandsOffItsBodyOnce(): void
    {
        $request = new Request('POST', '/items?id=42', body: '{"id":42}');
        Assert::false(new \ReflectionClass($request)->isCloneable());
        Assert::same($request->takeJson(), ['id' => 42]);

        Expect::exception(\LogicException::class);
        $request->takeBody();
    }

    public function messagesShareTheEmptyReaderAndHandItOffSeparately(): void
    {
        $response = new Response();
        $copy = $response->withHeader('accept', '*/*');
        Assert::same($copy->takeBody(), EMPTY_BODY);
        EMPTY_BODY->close();
        Assert::same(new Response()->takeBody(), EMPTY_BODY);
        Assert::same($response->withBody('')->takeBody(), EMPTY_BODY);
        Assert::same(EMPTY_BODY->fresh(), EMPTY_BODY);
        Assert::null(EMPTY_BODY->read(1));
        Assert::same(EMPTY_BODY->readAll(limit: 0), '');
        Assert::same(EMPTY_BODY->trailers(), EMPTY_HEADERS);

        Expect::exception(\LogicException::class);
        $response->takeBody();
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
