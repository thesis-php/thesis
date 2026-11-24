<?php

declare(strict_types=1);

namespace Thesis\HttpClient;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Thesis\Http\Headers;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Uri\Rfc3986\Uri;

#[Test]
#[Covers(FollowRedirects::class)]
final class FollowRedirectsTest
{
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
            Assert::null($sent->body->read(1));

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
            Assert::same($sent->body->read(10), 'payload');
            if ($sent->uri->getHost() === 'example.com') {
                return new Response(status: 307, headers: new Headers(['location' => '//other.test/upload']));
            }

            Assert::true($sent->headers->isEmpty);

            return new Response(status: 201);
        });
        Assert::same($response->status, 201);
    }

    public function leavesTheLastRedirectBodyAvailable(): void
    {
        $request = new Request('GET', new Uri('https://example.com'));
        foreach (['http://example.com', 'ftp://other.test', 'https://user@example.com', 'invalid path'] as $location) {
            $redirect = new Response(status: 301, headers: new Headers(['location' => $location]), body: 'redirect');
            Assert::same(new FollowRedirects()->handle($request, static fn() => $redirect), $redirect);
            Assert::same($redirect->body->read(10), 'redirect');
        }

        $calls = 0;
        $last = new FollowRedirects(maxRedirects: 2)->handle($request, static function () use (&$calls) {
            ++$calls;

            return new Response(status: 308, headers: new Headers(['location' => '/loop']), body: 'last');
        });
        Assert::same($calls, 3);
        Assert::same($last->body->read(4), 'last');
    }
}
