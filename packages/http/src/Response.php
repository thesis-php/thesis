<?php

declare(strict_types=1);

namespace Thesis\Http;

use Thesis\Http\Server\Internal\PendingBody;

/**
 * Outgoing HTTP response. Metadata copies share one body handoff.
 *
 * @api
 * @phpstan-import-type HeadersInput from Headers
 */
final readonly class Response
{
    /**
     * @param int<100, 999> $status
     */
    public static function html(string|Body $body, int $status = Status::OK): self
    {
        /** @var Headers $headers */
        static $headers = new Headers(['Content-Type' => 'text/html; charset=utf-8']);

        return new self(status: $status, headers: $headers, body: $body);
    }

    /**
     * @param int<100, 999> $status
     * @throws \JsonException if the value cannot be encoded
     */
    public static function json(mixed $value, int $status = Status::OK): self
    {
        /** @var Headers $headers */
        static $headers = new Headers(['Content-Type' => 'application/json']);

        return new self(status: $status, headers: $headers, body: json_encode($value, JSON_THROW_ON_ERROR));
    }

    public Headers $headers;

    private PendingBody $pendingBody;

    /**
     * @param int<100, 999> $status
     * @param HeadersInput $headers
     */
    public function __construct(
        public int $status = Status::OK,
        array|Headers $headers = EMPTY_HEADERS,
        string|Body $body = EMPTY_BODY,
    ) {
        $this->headers = Headers::normalize($headers);
        $this->pendingBody = new PendingBody($body);
    }

    /**
     * @param int<100, 999> $status
     */
    public function withStatus(int $status): self
    {
        return clone($this, ['status' => $status]);
    }

    /**
     * @param HeadersInput $headers
     */
    public function withHeaders(array|Headers $headers): self
    {
        return clone($this, ['headers' => Headers::normalize($headers)]);
    }

    /**
     * Appends the supplied lines after any existing lines of each field.
     *
     * @param HeadersInput $headers
     */
    public function withAddedHeaders(array|Headers $headers): self
    {
        return $this->withHeaders($this->headers->append(Headers::normalize($headers)));
    }

    /**
     * @param non-empty-string $name
     * @param string|list<string> $values
     */
    public function withHeader(string $name, string|array $values): self
    {
        return $this->withHeaders($this->headers->with($name, $values));
    }

    /**
     * @param non-empty-string $name
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
     * @throws \LogicException if this message or a copy has already handed off the body
     */
    public function takeBody(): Body
    {
        return $this->pendingBody->take();
    }

    /**
     * Takes the body and decodes the whole payload as JSON.
     * If you need trailers, use takeBody() and read the body to the end before calling trailers().
     *
     * @param int<1, 2147483647> $depth
     * @param non-negative-int $limit maximum payload bytes to collect
     * @throws \LogicException if the body has already been taken or partially read
     * @throws \OverflowException if the payload exceeds the limit
     * @throws \JsonException if decoding fails
     */
    public function takeJson(
        bool $associative = true,
        int $depth = 512,
        int $flags = 0,
        int $limit = Body::DEFAULT_BUFFER_LIMIT,
    ): mixed {
        return $this->pendingBody->takeJson($associative, $depth, $flags, $limit);
    }

    public function withBody(string|Body $body): self
    {
        return clone($this, ['pendingBody' => new PendingBody($body)]);
    }

    /**
     * Returns a copy with the value encoded as JSON and Content-Type set to application/json.
     *
     * @param positive-int $depth
     * @throws \JsonException if encoding fails and JSON_PARTIAL_OUTPUT_ON_ERROR is unset
     */
    public function withJson(mixed $value, int $flags = 0, int $depth = 512): self
    {
        $json = json_encode(value: $value, flags: $flags | JSON_THROW_ON_ERROR, depth: $depth);

        return clone($this, [
            'headers' => $this->headers->with('content-type', 'application/json'),
            'pendingBody' => new PendingBody($json),
        ]);
    }
}
