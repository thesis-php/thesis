<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Internal\Http1;

use Thesis\Http\Body;
use Thesis\Http\Headers;
use Thesis\Http\Method;
use Thesis\Http\Response;
use Thesis\Http\Status;
use const Thesis\Http\EMPTY_HEADERS;

/**
 * An HTTP/1.1 response head contains the status line and header lines. It carries the HTTP version, body framing
 * and whether the connection stays open.
 *
 * @internal
 */
final readonly class ResponseHead
{
    /**
     * `of()` builds a response head using the request's HTTP version. HTTP/1.1 uses chunked framing for
     * trailers or an unknown body length. HTTP/1.0 uses close-delimited framing for an unknown length.
     *
     * An eligible HEAD or 304 response can supply Content-Length as representation metadata.
     *
     * @param Body $body the body taken from the message, used again to write the payload
     * @param bool $isPersistent false closes the connection after the response. The connection is also closed
     *     if the request asked for it, or if the body lasts until the connection is closed
     * @param list<non-empty-string> $upgrade the protocols a 101 response switches the connection to
     * @throws \ValueError if the headers contain a field that belongs to the connection, or if a response
     *     that cannot have a body (to `HEAD`, 1xx, 204, 304) has one. Trailers require a body and HTTP/1.1.
     *     An explicit Content-Length requires an eligible response and at most 18 decimal digits.
     */
    public static function of(
        Response $response,
        RequestHead $request,
        Body $body,
        bool $isPersistent = true,
        array $upgrade = [],
    ): self {
        Fields::assertUpgrade($upgrade);
        $hasBody = self::hasBody($request->method, $response->status);
        Fields::assertEndToEnd(
            headers: $response->headers,
            allowContentLength: !$hasBody && self::allowsRepresentationLength($request->method, $response->status),
        );
        $length = $body->size;
        $hasTrailers = Fields::hasTrailers($response->headers, $body->mayHaveTrailers);

        if ($hasBody) {
            if ($hasTrailers && $request->version === Version::Http10) {
                throw new \ValueError('An HTTP/1.0 response cannot have trailers.');
            }

            $framing = match (true) {
                $hasTrailers => Framing::Chunked,
                $length !== null => Framing::Length,
                $request->version === Version::Http11 => Framing::Chunked,
                default => Framing::UntilClose,
            };
        } elseif ($length !== 0) {
            throw new \ValueError(\sprintf(
                'A %d response to %s must have no body, got a body of %s.',
                $response->status,
                $request->method,
                $length === null ? 'an unknown length' : "{$length} bytes",
            ));
        } elseif ($hasTrailers) {
            throw new \ValueError('A response without a body cannot have trailers.');
        } else {
            $framing = Framing::None;
        }

        return new self(
            status: $response->status,
            headers: $response->headers,
            version: $request->version,
            framing: $framing,
            length: $framing === Framing::Chunked ? null : $length,
            isPersistent: $isPersistent && $request->isPersistent && $framing !== Framing::UntilClose,
            upgrade: $upgrade,
        );
    }

    /**
     * Builds an empty HTTP/1.1 error response that closes the connection when the request head is unavailable.
     *
     * @param int<400, 599> $status
     */
    public static function forInvalidRequest(int $status): self
    {
        return new self(
            status: $status,
            headers: EMPTY_HEADERS,
            version: Version::Http11,
            framing: Framing::Length,
            length: 0,
            isPersistent: false,
            upgrade: [],
        );
    }

    /**
     * @param int<100, 999> $status
     * @param Headers $headers message fields, including the optional Content-Length for an eligible HEAD or 304
     * @param ?non-negative-int $length the number of bytes in the body, null if the body is chunked
     *     or lasts until the connection is closed
     * @param bool $isPersistent whether the connection stays open for the next request
     * @param list<non-empty-string> $upgrade the protocols a 101 response switches the connection to
     */
    private function __construct(
        public int $status,
        public Headers $headers,
        public Version $version,
        public Framing $framing,
        public ?int $length,
        public bool $isPersistent,
        public array $upgrade,
    ) {}

    /**
     * The bytes of the head, including the empty line that ends it.
     */
    public function toString(): string
    {
        $phrase = Status::reasonPhrase($this->status) ?? '';
        $bytes = "{$this->version->value} {$this->status} {$phrase}\r\n";

        return $bytes . Fields::format(
            headers: $this->headers,
            version: $this->version,
            framing: $this->framing,
            length: $this->length,
            isPersistent: $this->isPersistent,
            upgrade: $this->upgrade,
        );
    }

    private static function allowsRepresentationLength(string $method, int $status): bool
    {
        return $status === Status::NOT_MODIFIED
            || ($method === Method::HEAD && $status >= 200 && $status !== Status::NO_CONTENT);
    }

    private static function hasBody(string $method, int $status): bool
    {
        return $method !== Method::HEAD
            && $status >= 200
            && $status !== Status::NO_CONTENT
            && $status !== Status::NOT_MODIFIED
            && !($method === Method::CONNECT && $status < 300);
    }
}
