<?php

declare(strict_types=1);

namespace Thesis\Http\Http1;

use Thesis\Http\Headers;
use Thesis\Http\Internal\Http1\Fields;
use Thesis\Http\Method;
use Thesis\Http\Response;
use Thesis\Http\Status;
use Thesis\Io\SizedReader;
use function Thesis\Http\Internal\isFieldValue;
use function Thesis\Http\Internal\quote;

/**
 * An HTTP/1.1 response head contains the status line and header lines. A server writes the head to a
 * connection, and a client parses it. The head carries the response details, HTTP version, body framing
 * and whether the connection stays open.
 *
 * @api
 */
final readonly class ResponseHead
{
    private const array REASONS = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        103 => 'Early Hints',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        206 => 'Partial Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Content Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        421 => 'Misdirected Request',
        422 => 'Unprocessable Content',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
    ];

    /**
     * `parse()` parses the head of a response read from a connection, as strictly as {@see RequestHead::parse()}.
     *
     * A response without a body (to `HEAD`, or a 304) may still have `Content-Length`. There it frames nothing:
     * it is the length the body would have, so it stays among the headers.
     *
     * @param string $bytes the head up to the empty line that ends it, without that line
     * @param non-empty-string $method the method of the request, needed to find the end of the response:
     *     a response to `HEAD` has no body whatever its fields say, and a 2xx response to `CONNECT` starts a tunnel
     * @param positive-int $maxFields see {@see RequestHead::parse()}
     * @throws MalformedMessage
     */
    public static function parse(string $bytes, string $method, int $maxFields = 100): self
    {
        $lines = explode("\r\n", $bytes);
        $statusLine = array_shift($lines);
        // The reason phrase is optional, and servers omit the space before it as well: "HTTP/1.1 200".
        $parts = explode(' ', $statusLine, limit: 3);
        $version = Version::tryFrom($parts[0]);
        $status = $parts[1] ?? '';

        if (
            $version === null
            || \strlen($status) !== 3
            || strspn($status, '0123456789') !== 3
            || $status[0] === '0'
            || !isFieldValue($parts[2] ?? '')
        ) {
            throw new MalformedMessage(\sprintf(
                'A status line must be HTTP/1.0 or HTTP/1.1, a status of three digits and a reason phrase, got %s.',
                quote($statusLine),
            ));
        }

        /** @var int<100, 999> $status */
        $status = (int) $status;
        $fields = Fields::parse($lines, $version, $maxFields);
        $headers = new Headers($fields->endToEnd);
        $hasBody = self::hasBody($method, $status);

        if (
            !$hasBody
            && $fields->contentLength !== null
            && self::allowsHypotheticalLength($method, $status)
        ) {
            $headers = $headers->with('content-length', (string) $fields->contentLength);
        }

        $framing = match (true) {
            !$hasBody => Framing::None,
            $fields->isChunked => Framing::Chunked,
            $fields->contentLength !== null => Framing::Length,
            default => Framing::UntilClose,
        };

        return new self(
            status: $status,
            headers: $headers,
            version: $version,
            framing: $framing,
            length: match ($framing) {
                Framing::None => 0,
                Framing::Length => $fields->contentLength,
                Framing::Chunked, Framing::UntilClose => null,
            },
            isPersistent: $fields->isPersistent && $framing !== Framing::UntilClose,
            upgrade: $fields->upgrade,
        );
    }

    /**
     * `of()` builds a response head using the request's HTTP version. HTTP/1.1 uses chunked framing for
     * trailers or an unknown body length. HTTP/1.0 uses close-delimited framing for an unknown length.
     *
     * A response to `HEAD` or a 304 response can carry the length the body would have in `content-length`.
     *
     * @param bool $isPersistent false closes the connection after the response. The connection is also closed
     *     if the request asked for it, or if the body lasts until the connection is closed
     * @param list<non-empty-string> $upgrade the protocols a 101 response switches the connection to
     * @throws \ValueError if the headers contain a field that belongs to the connection, or if a response
     *     that cannot have a body (to `HEAD`, 1xx, 204, 304) has one. Trailers require a body and HTTP/1.1.
     */
    public static function of(
        Response $response,
        RequestHead $request,
        bool $isPersistent = true,
        array $upgrade = [],
    ): self {
        Fields::assertUpgrade($upgrade);
        $length = $response->reader instanceof SizedReader ? $response->reader->size : null;
        $hasTrailers = Fields::hasTrailers($response->headers, $response->trailers);

        if (self::hasBody($request->method, $response->status)) {
            Fields::assertEndToEnd($response->headers);
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
            Fields::assertEndToEnd(
                headers: $response->headers,
                allowContentLength: self::allowsHypotheticalLength($request->method, $response->status),
            );
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
     * @param int<100, 999> $status
     * @param Headers $headers without the fields that belong to the connection and the fields that `Connection`
     *     names
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
        $lines = [\sprintf('%s %d %s', $this->version->value, $this->status, self::REASONS[$this->status] ?? '')];

        array_push($lines, ...Fields::format(
            headers: $this->headers,
            version: $this->version,
            framing: $this->framing,
            length: $this->length,
            isPersistent: $this->isPersistent,
            upgrade: $this->upgrade,
        ));

        return implode("\r\n", $lines) . "\r\n\r\n";
    }

    private static function allowsHypotheticalLength(string $method, int $status): bool
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
