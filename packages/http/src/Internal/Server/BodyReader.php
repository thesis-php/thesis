<?php

declare(strict_types=1);

namespace Thesis\Http\Internal\Server;

use Thesis\Http\Headers;
use Thesis\Http\Http1\ChunkedReader;
use Thesis\Http\Http1\FixedLengthReader;
use Thesis\Http\Http1\MalformedMessage;
use Thesis\Http\Status;
use Thesis\Http\StreamingBody;
use Thesis\Io\IoException;

/**
 * @internal
 */
final class BodyReader extends StreamingBody
{
    #[\Override]
    public ?int $size {
        get => $this->body->size;
    }

    #[\Override]
    public bool $isBuffered {
        get => false;
    }

    #[\Override]
    public bool $mayHaveTrailers {
        get => $this->body->mayHaveTrailers;
    }

    public private(set) ?IoException $failure = null;

    /**
     * @param ?non-negative-int $remaining the remaining decoded-byte budget for a chunked body
     */
    public function __construct(
        private readonly FixedLengthReader|ChunkedReader $body,
        private ?ContinueResponse $continue,
        private ?int $remaining = null,
    ) {}

    private function __clone() {}

    protected function readPayload(int $max): ?string
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->sendContinue();

        try {
            return $this->readBody($max);
        } catch (IoException $failure) {
            $this->failure = $failure;

            throw $failure;
        }
    }

    public function trailers(): Headers
    {
        return $this->body->trailers();
    }

    public function close(): void
    {
        $this->body->close();
    }

    private function sendContinue(): void
    {
        if ($this->continue !== null) {
            $this->continue->send();
            $this->continue = null;
        }
    }

    /**
     * @param positive-int $max
     * @return ?non-empty-string
     */
    private function readBody(int $max): ?string
    {
        if ($this->remaining === null) {
            return $this->body->read($max);
        }

        if ($this->remaining === 0) {
            // The decoder still needs to read the final chunk and trailers at the limit.
            if ($this->body->read(1) !== null) {
                throw new MalformedMessage('The request body exceeds its byte limit.', Status::CONTENT_TOO_LARGE);
            }

            return null;
        }

        $bytes = $this->body->read(min($max, $this->remaining));
        if ($bytes !== null) {
            /** @var non-negative-int $remaining a reader returns at most the requested bytes */
            $remaining = $this->remaining - \strlen($bytes);
            $this->remaining = $remaining;
        }

        return $bytes;
    }
}
