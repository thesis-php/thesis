<?php

declare(strict_types=1);

namespace Thesis\Http;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Uri\Rfc3986\Uri;

#[Test]
#[Covers(Request::class)]
#[Covers(Response::class)]
#[Covers(Query::class)]
#[Covers(StringBody::class)]
final class MessageTest
{
    public function changingQueryAndHeadersLeavesTheOriginalRequestIntact(): void
    {
        $original = Request::get('/search?tag=a&sort=C++&tag=b#top');
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
        $request = Request::get('/');
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

    public function wholeBodyReadsRejectAPartiallyConsumedUpload(): void
    {
        $body = new StringBody('12345');
        Assert::same($body->read(2), '12');
        Assert::same($body->fresh()->readAll(), '12345');
        Expect::exception(\LogicException::class);
        $body->readAll();
    }
}
