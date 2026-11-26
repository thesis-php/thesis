<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Server\Internal\GzipBody;
use Thesis\Http\Status;

/**
 * Negotiates gzip for complete response bodies.
 *
 * @api
 */
final readonly class Gzip implements Middleware
{
    private const array EXCLUDED_STATUSES = [
        Status::NO_CONTENT,
        Status::RESET_CONTENT,
    ];

    /**
     * @throws \LogicException if ext-zlib is missing
     */
    public function __construct()
    {
        if (!\extension_loaded('zlib')) {
            throw new \LogicException('Server gzip compression requires ext-zlib.');
        }
    }

    public function handle(Request $request, Context $context, callable $next): Response
    {
        $response = $next();

        if ($response->status < Status::OK || \in_array($response->status, self::EXCLUDED_STATUSES, strict: true)) {
            return $response;
        }

        if ($request->method === Method::CONNECT && $response->status < Status::MULTIPLE_CHOICES) {
            return $response;
        }

        if ($response->headers->has('content-encoding')) {
            return $response;
        }

        $vary = $response->headers->get('vary');

        if ($vary === null || !self::variesOnEncoding($vary)) {
            $response = $response->withAddedHeader('vary', 'Accept-Encoding');
        }

        if ($response->status === Status::PARTIAL_CONTENT || $response->headers->has('content-range')) {
            return $response;
        }

        $encoding = self::negotiate($request->headers->get('accept-encoding') ?? '');

        if ($encoding === null) {
            return new Response(
                status: Status::NOT_ACCEPTABLE,
                headers: ['vary' => $response->headers->all('vary')],
            );
        }

        if ($encoding === 'identity') {
            return $response;
        }

        $response = $response
            ->withHeaders(GzipBody::encodedHeaders($response->headers))
            ->withoutHeader('content-length')
            ->withHeader('content-encoding', 'gzip')
        ;

        if ($request->method === Method::HEAD || $response->status === Status::NOT_MODIFIED) {
            return $response->withBody('');
        }

        return $response->withBody(new GzipBody($response->takeBody()));
    }

    private static function variesOnEncoding(string $field): bool
    {
        foreach (explode(',', strtolower($field)) as $name) {
            $name = trim($name);

            if ($name === '*' || $name === 'accept-encoding') {
                return true;
            }
        }

        return false;
    }

    private static function negotiate(string $field): ?string
    {
        if ($field === '') {
            return 'identity';
        }

        $weights = self::parseWeights($field);
        $wildcard = $weights['*'] ?? null;
        $gzip = $weights['gzip'] ?? $wildcard ?? 0.0;
        $identity = $weights['identity'] ?? null;

        if ($gzip > 0 && ($identity === null || $gzip >= $identity)) {
            return 'gzip';
        }

        // Without an explicit identity weight, only *;q=0 excludes the original body.
        if ($identity === 0.0 || ($identity === null && $wildcard === 0.0)) {
            return null;
        }

        return 'identity';
    }

    /**
     * @return array<string, float>
     */
    private static function parseWeights(string $field): array
    {
        $weights = [];

        foreach (explode(',', strtolower($field)) as $item) {
            $parts = explode(';', $item, 2);
            $coding = trim($parts[0]);

            if (!\in_array($coding, ['gzip', 'identity', '*'], strict: true)) {
                continue;
            }

            $weight = 1.0;

            if (isset($parts[1])) {
                $weight = preg_match('/^q=(0(?:\.[0-9]{0,3})?|1(?:\.0{0,3})?)$/D', trim($parts[1]), $match) === 1
                    ? (float) $match[1]
                    : 0.0;
            }

            $weights[$coding] = max($weight, $weights[$coding] ?? 0.0);
        }

        return $weights;
    }
}
