<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Test;
use Thesis\Dispatcher;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Status;
use Thesis\Http\StringBody;
use Thesis\Net\InetAddress;

#[Test]
final readonly class GzipTest
{
    private Context $context;

    public function __construct()
    {
        $address = new InetAddress('127.0.0.1', 80);
        $this->context = new Context($address, $address, null, new DefaultErrorHandler(), new Dispatcher());
    }

    public function refusesExcludedRepresentations(): void
    {
        $request = new Request('GET', '/', ['accept-encoding' => '*;q=0']);
        $response = new Gzip()->handle($request, $this->context, static fn() => new Response(body: 'payload'));
        Assert::same(Status::NOT_ACCEPTABLE, $response->status);
        Assert::same('Accept-Encoding', $response->headers->get('vary'));
    }

    #[DataSet(['gzip, br', true])]
    #[DataSet(['GZip;q=0.125', true])]
    #[DataSet(['*;q=0.8', true])]
    #[DataSet(['*;q=1, gzip;q=0', false])]
    #[DataSet(['gzip;q=0.3, identity;q=0.7', false])]
    #[DataSet(['gzip;q=1.5', false])]
    #[DataSet(['br', false])]
    #[DataSet(['', false])]
    public function negotiates(string $field, bool $compressed): void
    {
        $request = new Request('GET', '/', ['accept-encoding' => $field]);
        $payload = str_repeat('payload', 3000);
        $response = new Gzip()->handle($request, $this->context, static fn() => new Response(
            headers: ['vary' => 'Origin', 'content-length' => (string) \strlen($payload)],
            body: new StringBody($payload, ['x-end' => 'yes', 'repr-digest' => 'original']),
        ));
        Assert::same('Origin, Accept-Encoding', $response->headers->get('vary'));
        Assert::same($compressed ? 'gzip' : null, $response->headers->get('content-encoding'));
        Assert::same($compressed ? null : (string) \strlen($payload), $response->headers->get('content-length'));
        $body = $response->takeBody();
        $data = '';

        while (($bytes = $body->read(7)) !== null) {
            $data .= $bytes;
        }
        Assert::same($payload, $compressed ? gzdecode($data) : $data);
        Assert::same($compressed ? null : 'original', $body->trailers()->get('repr-digest'));
        Assert::same('yes', $body->trailers()->get('x-end'));
    }

    /**
     * @param non-empty-string $method
     * @param int<100, 999> $status
     */
    #[DataSet(['HEAD', Status::OK])]
    #[DataSet(['GET', Status::NOT_MODIFIED])]
    public function negotiatesRepresentationMetadata(string $method, int $status): void
    {
        $request = new Request($method, '/', ['accept-encoding' => 'gzip']);
        $response = new Gzip()->handle($request, $this->context, static fn() => new Response(
            status: $status,
            headers: ['content-length' => '99', 'etag' => '"v1"', 'vary' => 'Origin, ACCEPT-Encoding'],
        ));
        Assert::same('Origin, ACCEPT-Encoding', $response->headers->get('vary'));
        Assert::same('gzip', $response->headers->get('content-encoding'));
        Assert::same(null, $response->headers->get('content-length'));
        Assert::same('W/"v1"', $response->headers->get('etag'));
        Assert::same('', $response->takeBody()->readAll());
    }

    public function preservesRangeBytesAndVariesOnEncoding(): void
    {
        $request = new Request('GET', '/', ['accept-encoding' => 'gzip']);
        $response = new Gzip()->handle($request, $this->context, static fn() => new Response(
            status: Status::PARTIAL_CONTENT,
            body: 'part',
        ));
        Assert::same('Accept-Encoding', $response->headers->get('vary'));
        Assert::same(null, $response->headers->get('content-encoding'));
        Assert::same('part', $response->takeBody()->readAll());
    }

    public function compressesAnEmptyRepresentation(): void
    {
        $request = new Request('GET', '/', ['accept-encoding' => 'gzip, identity;q=0']);
        $response = new Gzip()->handle($request, $this->context, static fn() => new Response(headers: ['vary' => '*']));
        Assert::same('*', $response->headers->get('vary'));
        Assert::same('', gzdecode($response->takeBody()->readAll()));
    }

    /**
     * @param non-empty-string $method
     * @param int<100, 999> $status
     * @param array<non-empty-string, string> $headers
     */
    #[DataSet(['GET', Status::EARLY_HINTS, []])]
    #[DataSet(['GET', Status::NO_CONTENT, []])]
    #[DataSet(['GET', Status::RESET_CONTENT, []])]
    #[DataSet(['CONNECT', Status::OK, []])]
    #[DataSet(['GET', Status::OK, ['content-encoding' => 'br']])]
    public function preservesMetadata(string $method, int $status, array $headers): void
    {
        $request = new Request($method, '/', ['accept-encoding' => 'gzip']);
        $response = new Gzip()->handle($request, $this->context, static fn() => new Response($status, $headers));
        Assert::same($headers['vary'] ?? null, $response->headers->get('vary'));
        Assert::same($headers['content-length'] ?? null, $response->headers->get('content-length'));
        Assert::same($headers['content-encoding'] ?? null, $response->headers->get('content-encoding'));
        Assert::same('', $response->takeBody()->readAll());
    }
}
