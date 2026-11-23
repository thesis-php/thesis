<?php

declare(strict_types=1);

namespace Thesis\Http\Http1;

use Thesis\Http\Headers;
use Thesis\Http\Internal\Http1\Fields;
use Thesis\Http\Method;
use Thesis\Http\Request;
use Thesis\Http\Status;
use Thesis\Io\SizedReader;
use Uri\Rfc3986\Uri;
use function Thesis\Http\Internal\isToken;
use function Thesis\Http\Internal\quote;

/**
 * An HTTP/1.1 request head contains the request line and header lines. A client writes the head to a
 * connection, and a server parses it. The head carries the request details, HTTP version, body framing
 * and whether the connection stays open.
 *
 * @api
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
        $endToEnd = $fields->endToEnd;
        $hosts = $endToEnd['host'] ?? [];
        unset($endToEnd['host']);

        if (\count($hosts) > 1 || ($hosts === [] && $version === Version::Http11)) {
            throw new MalformedMessage(\sprintf('An HTTP/1.1 request must have one Host, got %d.', \count($hosts)));
        }

        $host = $hosts[0] ?? null;

        if ($host !== null && self::authority($host) === null) {
            throw new MalformedMessage(\sprintf('Host must be a host and an optional port, got %s.', quote($host)));
        }

        return new self(
            method: $method,
            uri: self::uri($method, $target, $scheme, $host),
            headers: new Headers($endToEnd),
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
     * `of()` builds the HTTP/1.1 request head a client sends. It uses chunked framing if the body length
     * is unknown or the request has trailers.
     *
     * @param bool $isPersistent false asks the server to close the connection after the response
     * @param list<non-empty-string> $upgrade the protocols to switch the connection to, for example `websocket`
     * @throws \ValueError if the method is not a token, the URI has no host, `CONNECT` has no port,
     *     or the headers contain a field that belongs to the connection
     */
    public static function of(Request $request, bool $isPersistent = true, array $upgrade = []): self
    {
        if (!isToken($request->method)) {
            throw new \ValueError(\sprintf('A method must be a token, got %s.', quote($request->method)));
        }

        if (\in_array($request->uri->getRawHost(), [null, ''], strict: true)) {
            throw new \ValueError(\sprintf(
                'The URI of a request must have a host, got %s.',
                quote($request->uri->toRawString()),
            ));
        }

        if (!self::hasValidPort($request->uri)) {
            throw new \ValueError(\sprintf(
                'A request port must be at most %d, got %d.',
                self::MAX_PORT,
                $request->uri->getPort(),
            ));
        }

        if ($request->method === Method::CONNECT && self::port($request->uri) === null) {
            throw new \ValueError(\sprintf(
                'The URI of CONNECT must have a port or the scheme http or https, got %s.',
                quote($request->uri->toRawString()),
            ));
        }

        Fields::assertUpgrade($upgrade);
        Fields::assertEndToEnd($request->headers);

        if ($request->headers->has('host')) {
            throw new \ValueError('The header "host" is written by the codec from the URI of a request.');
        }

        $length = $request->body instanceof SizedReader ? $request->body->size : null;
        $framing = match (true) {
            $length === null || Fields::hasTrailers($request->headers, $request->trailers) => Framing::Chunked,
            // POST, PUT and PATCH are expected to have content, so an empty body is sent as Content-Length: 0.
            $length > 0,
            \in_array($request->method, [Method::POST, Method::PUT, Method::PATCH], strict: true) => Framing::Length,
            default => Framing::None,
        };

        return new self(
            method: $request->method,
            uri: $request->uri,
            headers: $request->headers,
            version: Version::Http11,
            framing: $framing,
            length: $framing === Framing::Chunked ? null : $length,
            isPersistent: $isPersistent,
            upgrade: $upgrade,
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
     * The bytes of the head, including the empty line that ends it.
     */
    public function toString(): string
    {
        $lines = ["{$this->method} {$this->target()} {$this->version->value}"];
        $authority = self::authorityOf($this->uri);

        if ($authority !== null) {
            $lines[] = "Host: {$authority}";
        }

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

    /**
     * The host of a URI, with the port if the URI has one. User info is never included. Null if the URI
     * has no host.
     */
    private static function authorityOf(Uri $uri): ?string
    {
        $host = $uri->getRawHost();
        $port = $uri->getPort();

        return $host === null || $port === null ? $host : "{$host}:{$port}";
    }

    private static function port(Uri $uri): ?int
    {
        return $uri->getPort() ?? self::DEFAULT_PORTS[$uri->getScheme() ?? ''] ?? null;
    }

    private function target(): string
    {
        if ($this->method === Method::CONNECT) {
            return "{$this->uri->getRawHost()}:" . self::port($this->uri);
        }

        $path = $this->uri->getRawPath();
        $query = $this->uri->getRawQuery();

        if ($this->method === Method::OPTIONS && $path === '' && $query === null) {
            return '*';
        }

        if ($path === '') {
            $path = '/';
        }

        return $query === null ? $path : "{$path}?{$query}";
    }
}
