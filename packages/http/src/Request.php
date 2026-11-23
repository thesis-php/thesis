<?php

declare(strict_types=1);

namespace Thesis\Http;

use Thesis\Io\Reader;
use Thesis\Io\StringReader;
use Uri\Rfc3986\Uri;
use function Thesis\Http\Internal\discardBodyAndReadTrailers;
use function Thesis\Io\close;

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
     * @var null|Headers|(\Closure(Reader): ?Headers)
     */
    private readonly null|Headers|\Closure $trailerSource;

    /**
     * The trailer fields, or null while the reader has yet to receive them.
     * A copy can replace the trailer fields with `clone()`.
     */
    public ?Headers $trailers {
        get => $this->trailerSource instanceof \Closure
            ? ($this->trailerSource)($this->body)
            : $this->trailerSource;
        /** @param null|Headers|(callable(Reader): ?Headers) $value */
        set(Headers|callable|null $value) {
            // @phpstan-ignore property.readOnlyAssignNotInConstructor (clone() lets the hook reinitialize this field)
            $this->trailerSource = self::normalizeTrailers($value);
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
        public public(set) readonly Reader $body = new StringReader(''),
        null|Headers|callable $trailers = new Headers(),
    ) {
        $this->trailerSource = self::normalizeTrailers($trailers);
    }

    /**
     * Closes the body if it implements Closer. A copy sharing the same body observes the close too.
     */
    public function closeBody(): void
    {
        close($this->body);
    }

    /**
     * Reads and discards the remaining body bytes, then returns the trailers. May suspend during a read.
     *
     * @throws \LogicException if the trailer source still reports pending fields at the end of the body
     */
    public function discardBodyAndReadTrailers(): Headers
    {
        return discardBodyAndReadTrailers($this);
    }

    /**
     * @param null|Headers|(callable(Reader): ?Headers) $trailers
     * @return null|Headers|(\Closure(Reader): ?Headers)
     */
    private static function normalizeTrailers(null|Headers|callable $trailers): null|Headers|\Closure
    {
        if ($trailers === null || $trailers instanceof Headers) {
            return $trailers;
        }

        return $trailers(...);
    }
}
