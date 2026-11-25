<?php

declare(strict_types=1);

namespace Thesis\Http;

use Thesis\Http\Internal\PendingBody;
use Uri\Rfc3986\Uri;

/**
 * HTTP request shared by clients and servers. Metadata copies share one body handoff.
 *
 * @api
 */
final class Request
{
    public static function get(string|Uri $uri): self
    {
        return new self(Method::GET, \is_string($uri) ? new Uri($uri) : $uri);
    }

    private ?Query $parsedQuery = null;

    public Query $query {
        get => $this->parsedQuery ??= Query::parse($this->uri->getRawQuery() ?? '');
    }

    private readonly PendingBody $pendingBody;

    /**
     * @param non-empty-string $method
     */
    public function __construct(
        public readonly string $method,
        public readonly Uri $uri,
        public readonly Headers $headers = EMPTY_HEADERS,
        string|Body $body = EMPTY_BODY,
    ) {
        $this->pendingBody = new PendingBody($body);
    }

    /**
     * @param non-empty-string $method
     */
    public function withMethod(string $method): self
    {
        return clone($this, ['method' => $method]);
    }

    public function withUri(Uri $uri): self
    {
        return clone($this, ['uri' => $uri, 'parsedQuery' => null]);
    }

    public function withQuery(Query $query): self
    {
        $encoded = $query->toString();

        $uri = $this->uri->withQuery($encoded === '' ? null : $encoded);

        return clone($this, ['uri' => $uri, 'parsedQuery' => $query]);
    }

    /**
     * @param string|list<string> $values
     */
    public function withQueryParameter(string $name, string|array $values): self
    {
        return $this->withQuery($this->query->with($name, $values));
    }

    public function withHeaders(Headers $headers): self
    {
        return clone($this, ['headers' => $headers]);
    }

    /**
     * @param string|list<string> $values
     */
    public function withHeader(string $name, string|array $values): self
    {
        return $this->withHeaders($this->headers->with($name, $values));
    }

    /**
     * @param string|list<string> $values
     */
    public function withAddedHeader(string $name, string|array $values): self
    {
        return $this->withHeaders($this->headers->withAdded($name, $values));
    }

    public function withoutHeader(string $name): self
    {
        return $this->withHeaders($this->headers->without($name));
    }

    /**
     * Transfers the body to the caller. All metadata copies share this single handoff.
     *
     * @throws \LogicException if this request or a copy has already handed off the body
     */
    public function takeBody(): Body
    {
        return $this->pendingBody->take();
    }

    /**
     * Takes the body and decodes the whole payload as JSON.
     * If you need trailers, use takeBody() and read the body to the end before calling trailers().
     *
     * @param positive-int $depth
     * @param non-negative-int $limit maximum payload bytes to collect
     * @throws \LogicException if the body has already been taken or partially read
     * @throws \OverflowException if the payload exceeds the limit
     * @throws \JsonException if decoding fails with JSON_THROW_ON_ERROR enabled
     */
    public function takeJson(
        bool $associative = true,
        int $depth = 512,
        int $flags = JSON_THROW_ON_ERROR,
        int $limit = Body::DEFAULT_BUFFER_LIMIT,
    ): mixed {
        $body = $this->takeBody();
        $bytes = $body->readAll($limit);
        $body->close();

        return json_decode(
            json: $bytes,
            associative: $associative,
            depth: $depth,
            flags: $flags,
        );
    }

    public function withBody(string|Body $body): self
    {
        return clone($this, ['pendingBody' => new PendingBody($body)]);
    }
}
