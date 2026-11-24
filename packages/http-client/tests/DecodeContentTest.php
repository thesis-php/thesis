<?php

declare(strict_types=1);

namespace Thesis\HttpClient;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Thesis\Http\Headers;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\StringBody;
use Thesis\Io\ReadFailed;
use Uri\Rfc3986\Uri;

#[Test]
#[Covers(DecodeContent::class)]
final class DecodeContentTest
{
    public function decodesGzipMembersAndPreservesTrailers(): void
    {
        $request = new Request('GET', new Uri('https://example.org'));
        $response = new DecodeContent()->handle($request, static function (Request $request) {
            Assert::same($request->headers->get('accept-encoding'), 'gzip, deflate');
            $wire = gzencode('hello') . gzencode(' world');

            return new Response(
                headers: new Headers(['Content-Encoding' => 'GZip', 'Content-Length' => (string) \strlen($wire)]),
                body: new StringBody($wire, new Headers(['x-end' => 'ok'])),
            );
        });
        Assert::true($response->headers->isEmpty);
        Assert::null($response->body->size);
        Assert::true($response->body->mayHaveTrailers);
        Assert::same($response->body->read(2), 'he');
        Assert::same($response->body->read(16), 'llo');
        Assert::same($response->body->read(16), ' world');
        Assert::same($response->body->discard()->get('x-end'), 'ok');
        Assert::null($response->body->read(1));
    }

    public function decodesDeflateAndRejectsTruncation(): void
    {
        $request = new Request('GET', new Uri('https://example.org'));
        $wire = gzcompress('hello');
        \assert($wire !== false);
        $response = new DecodeContent()->handle($request, static fn() => new Response(
            headers: new Headers(['content-encoding' => 'deflate']),
            body: substr($wire, 0, -1),
        ));
        Assert::same($response->body->read(100), 'hello');
        Expect::exception(ReadFailed::class);
        $response->body->discard();
    }

    public function leavesExplicitNegotiationAndPartialRepresentationsAlone(): void
    {
        $request = new Request('GET', new Uri('https://example.org'));
        $encoded = new Response(headers: new Headers(['content-encoding' => 'gzip']), body: 'wire bytes');
        foreach (['accept-encoding', 'range'] as $name) {
            $explicit = clone($request, ['headers' => new Headers([$name => 'custom'])]);
            Assert::same(new DecodeContent()->handle($explicit, static fn() => $encoded), $encoded);
        }
        foreach ([204, 205, 206, 304] as $status) {
            $partial = clone($encoded, ['status' => $status]);
            Assert::same(new DecodeContent()->handle($request, static fn() => $partial), $partial);
        }
        $head = new Request('HEAD', $request->uri);
        Assert::same(new DecodeContent()->handle($head, static fn() => $encoded), $encoded);
    }
}
