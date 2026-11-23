<?php

declare(strict_types=1);

namespace Thesis\Http;

use Thesis\Io\Reader;
use Thesis\Io\StringReader;
use Uri\Rfc3986\Uri;

/**
 * HTTP/1.1 and HTTP/2 clients and servers share this request type. The request holds its method,
 * URI, message headers and body. The codec gets the host from the URI and the length from the body.
 * The headers exclude connection fields such as `Host`, `Content-Length`, `Transfer-Encoding` and `Connection`.
 *
 * The properties are `public(set)` so that `clone($request, ['uri' => $uri])` can make a changed copy.
 * The copy shares the reader with the original.
 *
 * @api
 */
final class Request
{
    /**
     * @var \Closure(Reader): ?Headers
     */
    private readonly \Closure $trailersLoader;

    /**
     * The trailer fields, or null while the reader has yet to receive them.
     * A copy can replace the trailer fields with `clone()`.
     */
    public ?Headers $trailers {
        get => ($this->trailersLoader)($this->reader);
        /** @param null|Headers|(callable(Reader): ?Headers) $value */
        set(Headers|callable|null $value) {
            // @phpstan-ignore property.readOnlyAssignNotInConstructor (clone() lets the hook reinitialize this field)
            $this->trailersLoader = self::normalizeTrailers($value);
        }
    }

    /**
     * @param non-empty-string $method
     * @param null|Headers|(callable(Reader): ?Headers) $trailers ready fields, null while they are unavailable,
     *     or a callback that reads the current reader's trailer state without I/O or suspension
     */
    public function __construct(
        public public(set) readonly string $method,
        public public(set) readonly Uri $uri,
        public public(set) readonly Headers $headers = new Headers(),
        public public(set) readonly Reader $reader = new StringReader(''),
        null|Headers|callable $trailers = new Headers(),
    ) {
        $this->trailersLoader = self::normalizeTrailers($trailers);
    }

    /**
     * @param null|Headers|(callable(Reader): ?Headers) $trailers
     * @return \Closure(Reader): ?Headers
     */
    private static function normalizeTrailers(null|Headers|callable $trailers): \Closure
    {
        if ($trailers === null || $trailers instanceof Headers) {
            return static fn() => $trailers;
        }

        return $trailers(...);
    }
}
