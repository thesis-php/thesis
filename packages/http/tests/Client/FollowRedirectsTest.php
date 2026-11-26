<?php

declare(strict_types=1);

namespace Thesis\Http\Client;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Thesis\Http\Client\Stub\ReplayableUpload;
use Thesis\Http\Headers;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Io\WriteFailed;
use Uri\Rfc3986\Uri;
use function Thesis\Http\getRequest;
use function Thesis\Net\socketPair;

#[Test]
#[Covers(FollowRedirects::class)]
final class FollowRedirectsTest
{
    public function closesAnUnclaimedUploadWhenMiddlewareShortCircuits(): void
    {
        [$upload, $peer] = socketPair();
        $request = Form::multipart('http://example.test', [new FormPart('file', $upload)]);
        $cached = new Response(body: 'cached');
        $response = new FollowRedirects()->handle($request, static fn() => $cached);

        Assert::same($response, $cached);
        Assert::true($upload->isClosed);
        $peer->close();
    }

    public function droppingTheLastRequestCopyClosesItsUnclaimedUpload(): void
    {
        [$upload, $peer] = socketPair();
        $request = Form::multipart('http://example.test', [new FormPart('file', $upload)]);
        $copy = clone $request;
        $replacement = $copy->withBody('new');

        unset($request);
        Assert::false($upload->isClosed);
        unset($copy);
        Assert::true($upload->isClosed);
        Assert::same($replacement->takeBody()->readAll(), 'new');
        $peer->close();
    }

    public function resolvesRelativeTargetsAndRewritesPost(): void
    {
        $request = new Request(
            method: 'POST',
            uri: new Uri('https://example.com/a/start'),
            headers: new Headers(['authorization' => 'token', 'content-type' => 'text/plain']),
            body: 'payload',
        );
        $response = new FollowRedirects()->handle($request, static function (Request $sent) {
            if ($sent->method === 'POST') {
                return new Response(status: 302, headers: new Headers(['location' => '../end?q=1#anchor']));
            }

            Assert::same($sent->method, 'GET');
            Assert::same($sent->uri->toRawString(), 'https://example.com/end?q=1');
            Assert::same($sent->headers->get('authorization'), 'token');
            Assert::false($sent->headers->has('content-type'));
            Assert::null($sent->takeBody()->read(1));

            return new Response(status: 204);
        });
        Assert::same($response->status, 204);
    }

    public function sendsFreshUploadAndDropsPrivateHeadersOnAnotherOrigin(): void
    {
        $request = new Request(
            method: 'PUT',
            uri: new Uri('https://example.com/upload'),
            headers: new Headers(['cookie' => 'secret', 'x-api-key' => 'secret']),
            body: 'payload',
        );
        $response = new FollowRedirects()->handle($request, static function (Request $sent) {
            Assert::same($sent->method, 'PUT');
            Assert::same($sent->takeBody()->read(10), 'payload');
            if ($sent->uri->getHost() === 'example.com') {
                return new Response(status: 307, headers: new Headers(['location' => '//other.test/upload']));
            }

            Assert::true($sent->headers->isEmpty);

            return new Response(status: 201);
        });
        Assert::same($response->status, 201);
    }

    public function retriesARedirectedUploadAndFollowsAnotherRedirect(): void
    {
        $request = new Request('PUT', new Uri('http://example.com/start'), body: new ReplayableUpload('payload'));
        $paths = [];
        $send = static function (Request $attempt) use (&$paths) {
            $paths[] = $attempt->uri->getRawPath();
            Assert::same($attempt->takeBody()->readAll(), 'payload');

            return match (\count($paths)) {
                1 => new Response(status: 307, headers: new Headers(['location' => '/second'])),
                2 => throw new WriteFailed(),
                3 => new Response(status: 308, headers: new Headers(['location' => '/last'])),
                default => new Response(status: 204),
            };
        };

        $retry = new RetryRequests();
        $response = new FollowRedirects()->handle($request, static fn(Request $hop) => $retry->handle($hop, $send));
        Assert::same($response->status, 204);
        Assert::same($paths, ['/start', '/second', '/second', '/last']);
    }

    public function leavesTheLastRedirectBodyAvailable(): void
    {
        $uri = new Uri('https://example.com');
        foreach (['http://example.com', 'ftp://other.test', 'https://user@example.com', 'invalid path'] as $location) {
            $redirect = new Response(status: 301, headers: new Headers(['location' => $location]), body: 'redirect');
            Assert::same(new FollowRedirects()->handle(getRequest($uri), static fn() => $redirect), $redirect);
            Assert::same($redirect->takeBody()->read(10), 'redirect');
        }

        $calls = 0;
        $last = new FollowRedirects(maxRedirects: 2)->handle(getRequest($uri), static function () use (&$calls) {
            ++$calls;

            return new Response(status: 308, headers: new Headers(['location' => '/loop']), body: 'last');
        });
        Assert::same($calls, 3);
        Assert::same($last->takeBody()->read(4), 'last');
    }
}
