<?php

declare(strict_types=1);

namespace Thesis\Http;

use Thesis\Http\Server\Internal\PendingBody;
use Uri\InvalidUriException;
use Uri\Rfc3986\Uri;

/**
 * Incoming HTTP request.
 *
 * @api
 * @phpstan-import-type HeadersInput from Headers
 */
final class Request
{
    public readonly Headers $headers;

    private readonly PendingBody $pendingBody;

    public readonly Uri $uri;

    private ?Query $parsedQuery = null;

    public Query $query {
        get => $this->parsedQuery ??= Query::parse($this->uri->getRawQuery() ?? '');
    }

    /**
     * @param non-empty-string $method
     * @param HeadersInput $headers
     * @throws InvalidUriException if the URI string is invalid
     */
    public function __construct(
        public readonly string $method,
        string|Uri $uri,
        array|Headers $headers = EMPTY_HEADERS,
        string|Body $body = EMPTY_BODY,
    ) {
        $this->uri = \is_string($uri) ? new Uri($uri) : $uri;
        $this->headers = Headers::normalize($headers);
        $this->pendingBody = new PendingBody($body);
    }

    /**
     * Transfers the body to the caller.
     *
     * @throws \LogicException if the request already handed off its body
     */
    public function takeBody(): Body
    {
        return $this->pendingBody->take();
    }

    /**
     * Takes the body and decodes the whole payload as JSON.
     * If you need trailers, use takeBody() and read the body to the end before calling trailers().
     *
     * @param int<1, 2147483647> $depth
     * @param non-negative-int $limit maximum payload bytes to collect
     * @throws \LogicException if the body has already been taken or partially read
     * @throws \OverflowException if the payload exceeds the limit
     * @throws \JsonException if decoding fails
     */
    public function takeJson(
        bool $associative = true,
        int $depth = 512,
        int $flags = 0,
        int $limit = Body::DEFAULT_BUFFER_LIMIT,
    ): mixed {
        return $this->pendingBody->takeJson($associative, $depth, $flags, $limit);
    }

    /**
     * Transfers the request body and its trailers to a streaming 200 response.
     *
     * @throws \LogicException if the request already handed off its body
     */
    public function echo(): Response
    {
        return new Response(body: $this->takeBody());
    }

    private function __clone() {}
}
