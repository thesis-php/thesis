<?php

declare(strict_types=1);

namespace Thesis\Http;

use Thesis\Http\Internal\PendingBody;

/**
 * HTTP response shared by clients and servers. Metadata copies share one body handoff.
 *
 * @api
 */
final readonly class Response
{
    /**
     * @param int<100, 999> $status
     */
    public static function html(
        string|Body $body,
        int $status = Status::OK,
        Headers $headers = EMPTY_HEADERS,
    ): self {
        return new self(
            status: $status,
            headers: $headers->has('content-type')
                ? $headers
                : $headers->with('content-type', 'text/html; charset=utf-8'),
            body: $body,
        );
    }

    /**
     * @param int<100, 999> $status
     * @throws \JsonException if the value cannot be encoded
     */
    public static function json(
        mixed $value,
        int $status = Status::OK,
        Headers $headers = EMPTY_HEADERS,
    ): self {
        return new self(
            status: $status,
            headers: $headers->has('content-type')
                ? $headers
                : $headers->with('content-type', 'application/json'),
            body: json_encode($value, JSON_THROW_ON_ERROR),
        );
    }

    private PendingBody $pendingBody;

    /**
     * @param int<100, 999> $status
     */
    public function __construct(
        public int $status = Status::OK,
        public Headers $headers = EMPTY_HEADERS,
        string|Body $body = EMPTY_BODY,
    ) {
        $this->pendingBody = new PendingBody($body);
    }

    /**
     * @param int<100, 999> $status
     */
    public function withStatus(int $status): self
    {
        return clone($this, ['status' => $status]);
    }

    public function withHeaders(Headers $headers): self
    {
        return clone($this, ['headers' => $headers]);
    }

    /**
     * @param string|list<string> $values
     */
    public function withHeader(string $name, string|array $values): self
    {
        return $this->withHeaders($this->headers->with($name, $values));
    }

    /**
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
     * @throws \LogicException if this response or a copy has already handed off the body
     */
    public function takeBody(): Body
    {
        return $this->pendingBody->take();
    }

    /**
     * Transfers the body in its original form for HTTP/1 output.
     *
     * @internal
     */
    public function takePayload(): string|Body
    {
        return $this->pendingBody->takePayload();
    }

    /**
     * Takes the body and decodes the whole payload as JSON.
     * If you need trailers, use takeBody() and read the body to the end before calling trailers().
     *
     * @param positive-int $depth
     * @param non-negative-int $limit maximum payload bytes to collect
     * @throws \LogicException if the body has already been taken or partially read
     * @throws \OverflowException if the payload exceeds the limit
     * @throws \JsonException if decoding fails with JSON_THROW_ON_ERROR enabled
     */
    public function takeJson(
        bool $associative = true,
        int $depth = 512,
        int $flags = JSON_THROW_ON_ERROR,
        int $limit = Body::DEFAULT_BUFFER_LIMIT,
    ): mixed {
        $body = $this->takeBody();
        $bytes = $body->readAll($limit);
        $body->close();

        return json_decode(
            json: $bytes,
            associative: $associative,
            depth: $depth,
            flags: $flags,
        );
    }

    public function withBody(string|Body $body): self
    {
        return clone($this, ['pendingBody' => new PendingBody($body)]);
    }
}
