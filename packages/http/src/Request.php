<?php

declare(strict_types=1);

namespace Thesis\Http;

use Thesis\Io\Reader;
use Uri\Rfc3986\Uri;
use const Thesis\Http\Internal\EMPTY_HEADERS;

/**
 * HTTP/1.1 and HTTP/2 clients and servers share this request type. The request holds its method,
 * URI, message headers and body. The codec gets the host from the URI and the length from the body.
 * The headers exclude connection fields such as `Host`, `Content-Length`, `Transfer-Encoding` and `Connection`.
 *
 * `clone($request, ['uri' => $uri])` derives the query from the new URI.
 * `clone($request, ['query' => $query])` updates the query component of the URI.
 * The copy shares the reader with the original.
 *
 * @api
 */
final class Request
{
    public public(set) readonly Body $body;

    // @phpstan-ignore property.uninitializedReadonly (parsed on first access)
    private readonly Query $parsedQuery;

    public Query $query {
        // @phpstan-ignore property.readOnlyAssignNotInConstructor (first access initializes the cache)
        get => $this->parsedQuery ??= Query::parse($this->uri->getRawQuery() ?? '');
        set {
            $encoded = $value->toString();
            // @phpstan-ignore property.readOnlyAssignNotInConstructor (clone() permits replacing the URI)
            $this->uri = $this->uri->withQuery($encoded === '' ? null : $encoded);
            // @phpstan-ignore property.readOnlyAssignNotInConstructor (clone() initializes its own cache)
            $this->parsedQuery = $value;
        }
    }

    /**
     * @param non-empty-string $method
     */
    public function __construct(
        public public(set) readonly string $method,
        public public(set) readonly Uri $uri,
        public public(set) readonly Headers $headers = EMPTY_HEADERS,
        string|Reader $body = '',
    ) {
        $this->body = $body instanceof Body ? $body : new Body($body);
    }

    public function __clone(): void
    {
        // @phpstan-ignore unset.readOnlyProperty (a clone may replace its URI)
        unset($this->parsedQuery);
    }
}
