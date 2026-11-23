<?php

declare(strict_types=1);

namespace Thesis\Http;

use Thesis\Io\Reader;
use Thesis\Io\StringReader;
use function Thesis\Http\Internal\discardBodyAndReadTrailers;

/**
 * HTTP/1.1 and HTTP/2 clients and servers share this response type. The response holds a status,
 * message headers and a body. The codec gets the length from the body. The headers exclude connection
 * fields such as `Content-Length`, `Transfer-Encoding` and `Connection`. The response has no reason phrase
 * because HTTP/2 does not use reason phrases.
 *
 * The properties are `public(set)` so that `clone($response, ['status' => Status::NOT_FOUND])` can make a changed copy.
 * The copy shares the reader with the original.
 *
 * @api
 */
final class Response
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
     * @param int<100, 999> $status not an enum: anyone may define a status code
     * @param null|Headers|(callable(Reader): ?Headers) $trailers ready fields, null while they are unavailable,
     *     or a callback that reads the current reader's trailer state without I/O or suspension
     */
    public function __construct(
        public public(set) readonly int $status = Status::OK,
        public public(set) readonly Headers $headers = new Headers(),
        public public(set) readonly Reader $body = new StringReader(''),
        null|Headers|callable $trailers = new Headers(),
    ) {
        $this->trailerSource = self::normalizeTrailers($trailers);
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
