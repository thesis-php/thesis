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

    public function withBody(string|Body $body): self
    {
        return clone($this, ['pendingBody' => new PendingBody($body)]);
    }
}
