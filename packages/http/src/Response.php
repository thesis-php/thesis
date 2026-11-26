<?php

declare(strict_types=1);

namespace Thesis\Http;

/**
 * HTTP response shared by clients and servers. Metadata copies share one body handoff.
 *
 * @api
 * @phpstan-import-type HeadersInput from Headers
 */
final class Response extends Message
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

    /**
     * @param int<100, 999> $status
     * @param HeadersInput $headers
     */
    public function __construct(
        public readonly int $status = Status::OK,
        array|Headers $headers = EMPTY_HEADERS,
        string|Body $body = EMPTY_BODY,
    ) {
        parent::__construct($headers, $body);
    }

    /**
     * @param int<100, 999> $status
     */
    public function withStatus(int $status): self
    {
        return clone($this, ['status' => $status]);
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
}
