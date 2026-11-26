<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Internal\Http1;

use Thesis\Http\Headers;
use Thesis\Http\Method;
use Thesis\Http\Status;
use Uri\Rfc3986\Uri;
use function Thesis\Http\Server\Internal\isToken;
use function Thesis\Http\Server\Internal\quote;

/**
 * An HTTP/1.1 request head contains the request line and header lines. The server parses it. The head carries the request details, HTTP version, body framing
 * and whether the connection stays open.
 *
 * @internal
 */
final readonly class RequestHead
{
    /**
     * RFC 3986 forbids these bytes in a URI, but browsers send them unescaped in a target (`?filter[name]=x`).
     */
    private const array TARGET_ESCAPES = [
        '[' => '%5B',
        ']' => '%5D',
        '\\' => '%5C',
        '^' => '%5E',
        '`' => '%60',
        '{' => '%7B',
        '|' => '%7C',
        '}' => '%7D',
    ];
    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];
    private const int MAX_PORT = 65535;

    /**
     * `parse()` parses the head of a request read from a connection. Any input that two recipients could understand
     * differently is rejected, never guessed: a bare CR or LF, a folded line, a space before a colon,
     * `Content-Length` together with `Transfer-Encoding` or repeated, a transfer coding other than `chunked`,
     * a missing or repeated `Host`.
     *
     * For a path target, the parser percent-encodes the bytes listed in {@see TARGET_ESCAPES}.
     *
     * @param string $bytes the head up to the empty line that ends it, without that line
     * @param 'http'|'https' $scheme the scheme of the connection: a request names its host, but not its scheme
     * @param positive-int $maxFields PHP arrays do not randomize their hash, so thousands of colliding names
     *     would make the parsing quadratic
     * @throws MalformedMessage
     */
    public static function parse(string $bytes, string $scheme = 'http', int $maxFields = 100): self
    {
        $lines = explode("\r\n", $bytes);
        $requestLine = array_shift($lines);
        $parts = explode(' ', $requestLine);

        if (\count($parts) !== 3) {
            throw new MalformedMessage(\sprintf(
                'A request line must be a method, a target and a version with a space between them, got %s.',
                quote($requestLine),
            ));
        }

        [$method, $target, $versionName] = $parts;

        if (!isToken($method)) {
            throw new MalformedMessage(\sprintf('A method must be a token, got %s.', quote($method)));
        }

        $version = Version::tryFrom($versionName) ?? throw new MalformedMessage(
            message: \sprintf('The version of a request must be HTTP/1.0 or HTTP/1.1, got %s.', quote($versionName)),
            status: preg_match('~^HTTP/\d\.\d$~D', $versionName) === 1
                ? Status::HTTP_VERSION_NOT_SUPPORTED
                : Status::BAD_REQUEST,
        );
        $fields = Fields::parse($lines, $version, $maxFields);
        $hosts = $fields->endToEnd['host'] ?? [];

        if (\count($hosts) > 1 || ($hosts === [] && $version === Version::Http11)) {
            throw new MalformedMessage(\sprintf('An HTTP/1.1 request must have one Host, got %d.', \count($hosts)));
        }

        $host = $hosts[0] ?? null;

        /** @var ?string $lastValidHost */
        static $lastValidHost = null;
        if ($host !== null && $host !== $lastValidHost) {
            if (self::authority($host) === null) {
                throw new MalformedMessage(\sprintf('Host must be a host and an optional port, got %s.', quote($host)));
            }

            // Keep at most one short authority across requests, regardless of how many hosts a peer sends.
            $lastValidHost = \strlen($host) <= 255 ? $host : null;
        }

        return new self(
            method: $method,
            uri: self::uri($method, $target, $scheme, $host),
            headers: $fields->toHeaders(withoutHost: true),
            version: $version,
            framing: match (true) {
                $fields->isChunked => Framing::Chunked,
                $fields->contentLength !== null => Framing::Length,
                default => Framing::None,
            },
            length: $fields->isChunked ? null : $fields->contentLength ?? 0,
            isPersistent: $fields->isPersistent,
            upgrade: $fields->upgrade,
        );
    }

    /**
     * @param non-empty-string $method
     * @param Uri $uri the target with the scheme of the connection and the host from `Host`. An HTTP/1.0 request
     *     without `Host` has only the target. `OPTIONS *` has an empty path, `CONNECT` has only a host and a port
     * @param Headers $headers without the fields that belong to the connection and the fields that `Connection`
     *     names: a proxy must not forward them
     * @param ?non-negative-int $length the number of bytes in the body, null if the body is chunked
     * @param bool $isPersistent whether the connection stays open for the next request
     * @param list<non-empty-string> $upgrade the protocols the client offers to switch the connection to
     */
    private function __construct(
        public string $method,
        public Uri $uri,
        public Headers $headers,
        public Version $version,
        public Framing $framing,
        public ?int $length,
        public bool $isPersistent,
        public array $upgrade,
    ) {}

    /**
     * @throws MalformedMessage
     */
    private static function uri(string $method, string $target, string $scheme, ?string $host): Uri
    {
        if ($method === Method::CONNECT) {
            $uri = self::authority($target);

            if ($uri === null || $uri->getPort() === null) {
                throw new MalformedMessage(\sprintf(
                    'The target of CONNECT must be a host and a port, got %s.',
                    quote($target),
                ));
            }

            return $uri;
        }

        if ($target === '*' && $method === Method::OPTIONS) {
            return new Uri($host === null ? '' : "{$scheme}://{$host}");
        }

        // A target that starts with a slash is a path.
        if (str_starts_with($target, '/')) {
            // With no host in front, "//evil.example/path" would be parsed as a URI of the host evil.example.
            if ($host === null && str_starts_with($target, '//')) {
                throw new MalformedMessage(\sprintf(
                    'A target that starts with two slashes needs Host, got %s.',
                    quote($target),
                ));
            }

            $uri = Uri::parse(($host === null ? '' : "{$scheme}://{$host}") . strtr($target, self::TARGET_ESCAPES));

            if ($uri === null || $uri->getRawFragment() !== null) {
                throw new MalformedMessage(\sprintf(
                    'A target must be a valid RFC 3986 path with an optional query, got %s.',
                    quote($target),
                ));
            }

            return $uri;
        }

        return self::absolute($target) ?? throw new MalformedMessage(\sprintf(
            'A target must be a path, an http or https URI without user info and a fragment, or "*" for OPTIONS, got %s.',
            quote($target),
        ));
    }

    /**
     * `absolute()` parses a target that is a full URI: a client sends such a target to a proxy. Null if the target
     * is not a URI that a server accepts.
     */
    private static function absolute(string $target): ?Uri
    {
        $uri = Uri::parse($target);

        if (
            $uri === null
            || !isset(self::DEFAULT_PORTS[$uri->getScheme() ?? ''])
            || \in_array($uri->getRawHost(), [null, ''], strict: true)
            || $uri->getRawUserInfo() !== null
            || $uri->getRawFragment() !== null
            || !self::hasValidPort($uri)
        ) {
            return null;
        }

        return $uri;
    }

    /**
     * `authority()` parses a host with an optional port: the value of `Host` or the target of `CONNECT`. Null if
     * the string
     * is anything else.
     */
    private static function authority(string $authority): ?Uri
    {
        $uri = Uri::parse("//{$authority}");

        if (
            $uri === null
            || \in_array($uri->getRawHost(), [null, ''], strict: true)
            || $uri->getRawUserInfo() !== null
            || $uri->getRawPath() !== ''
            || $uri->getRawQuery() !== null
            || $uri->getRawFragment() !== null
            || !self::hasValidPort($uri)
        ) {
            return null;
        }

        return $uri;
    }

    private static function hasValidPort(Uri $uri): bool
    {
        $port = $uri->getPort();

        return $port === null || $port <= self::MAX_PORT;
    }
}
