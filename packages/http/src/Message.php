<?php

declare(strict_types=1);

namespace Thesis\Http;

use Thesis\Http\Internal\PendingBody;

/**
 * HTTP message whose metadata copies share one body handoff.
 *
 * @api
 * @phpstan-import-type HeadersInput from Headers
 * @phpstan-sealed Request|Response
 */
abstract class Message
{
    public readonly Headers $headers;

    private readonly PendingBody $pendingBody;

    /**
     * @param HeadersInput $headers
     */
    protected function __construct(
        array|Headers $headers,
        string|Body $body,
    ) {
        $this->headers = Headers::normalize($headers);
        $this->pendingBody = new PendingBody($body);
    }

    /**
     * @param HeadersInput $headers
     */
    final public function withHeaders(array|Headers $headers): static
    {
        return clone($this, ['headers' => Headers::normalize($headers)]);
    }

    /**
     * Appends the supplied lines after any existing lines of each field.
     *
     * @param HeadersInput $headers
     */
    final public function withAddedHeaders(array|Headers $headers): static
    {
        return $this->withHeaders($this->headers->append(Headers::normalize($headers)));
    }

    /**
     * @param non-empty-string $name
     * @param string|list<string> $values
     */
    final public function withHeader(string $name, string|array $values): static
    {
        return $this->withHeaders($this->headers->with($name, $values));
    }

    /**
     * @param non-empty-string $name
     * @param string|list<string> $values
     */
    final public function withAddedHeader(string $name, string|array $values): static
    {
        return $this->withHeaders($this->headers->withAdded($name, $values));
    }

    final public function withoutHeader(string $name): static
    {
        return $this->withHeaders($this->headers->without($name));
    }

    /**
     * Transfers the body to the caller. All metadata copies share this single handoff.
     *
     * @throws \LogicException if this message or a copy has already handed off the body
     */
    final public function takeBody(): Body
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
    final public function takeJson(
        bool $associative = true,
        int $depth = 512,
        int $flags = 0,
        int $limit = Body::DEFAULT_BUFFER_LIMIT,
    ): mixed {
        $body = $this->takeBody();
        $bytes = $body->readAll($limit);
        $body->close();

        return json_decode(json: $bytes, associative: $associative, depth: $depth, flags: $flags | JSON_THROW_ON_ERROR);
    }

    final public function withBody(string|Body $body): static
    {
        return clone($this, ['pendingBody' => new PendingBody($body)]);
    }

    /**
     * Returns a copy with the value encoded as JSON and Content-Type set to application/json.
     *
     * @param positive-int $depth
     * @throws \JsonException if encoding fails and JSON_PARTIAL_OUTPUT_ON_ERROR is unset
     */
    final public function withJson(mixed $value, int $flags = 0, int $depth = 512): static
    {
        $json = json_encode(value: $value, flags: $flags | JSON_THROW_ON_ERROR, depth: $depth);

        return clone($this, [
            'headers' => $this->headers->with('content-type', 'application/json'),
            'pendingBody' => new PendingBody($json),
        ]);
    }
}
