<?php

declare(strict_types=1);

namespace Thesis\Http;

use Uri\InvalidUriException;
use Uri\Rfc3986\Uri;

/**
 * HTTP request shared by clients and servers. Metadata copies share one body handoff.
 *
 * @api
 * @phpstan-import-type HeadersInput from Headers
 * @phpstan-import-type QueryInput from Query
 */
final class Request extends Message
{
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
        parent::__construct($headers, $body);
    }

    /**
     * @param non-empty-string $method
     */
    public function withMethod(string $method): self
    {
        return clone($this, ['method' => $method]);
    }

    /**
     * @throws InvalidUriException if the URI string is invalid
     */
    public function withUri(string|Uri $uri): self
    {
        return clone($this, ['uri' => \is_string($uri) ? new Uri($uri) : $uri, 'parsedQuery' => null]);
    }

    /**
     * @param QueryInput $query
     * @throws InvalidUriException if the encoded query string is invalid
     */
    public function withQuery(string|array|Query $query): self
    {
        if (\is_string($query)) {
            return clone($this, ['uri' => $this->uri->withQuery($query), 'parsedQuery' => null]);
        }

        if (\is_array($query)) {
            $query = new Query($query);
        }

        $encoded = $query->toString();

        $uri = $this->uri->withQuery($encoded === '' ? null : $encoded);

        return clone($this, ['uri' => $uri, 'parsedQuery' => $query]);
    }

    /**
     * Appends query pairs while preserving the original encoded query.
     *
     * @param QueryInput $query
     * @throws InvalidUriException if the encoded query string is invalid
     */
    public function withAddedQuery(string|array|Query $query): self
    {
        if (\is_array($query)) {
            $query = new Query($query);
        }

        $suffix = \is_string($query) ? $query : $query->toString();
        if ($suffix === '') {
            return $this;
        }

        $current = $this->uri->getRawQuery();

        return $this->withQuery($current === null || $current === '' ? $suffix : $current . '&' . $suffix);
    }

    /**
     * @param non-empty-string $name
     * @param string|list<string> $values
     */
    public function withQueryParameter(string $name, string|array $values): self
    {
        return $this->withQuery($this->query->with($name, $values));
    }

    /**
     * @param non-empty-string $name
     * @param string|list<string> $values
     */
    public function withAddedQueryParameter(string $name, string|array $values): self
    {
        return $this->withAddedQuery([$name => $values]);
    }

    public function withoutQueryParameter(string $name): self
    {
        return $this->withQuery($this->query->without($name));
    }
}
