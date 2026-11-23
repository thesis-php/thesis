<?php

declare(strict_types=1);

namespace Thesis\HttpServer\Internal;

use Thesis\Http\Http1\MalformedMessage;
use Thesis\Http\Status;
use Thesis\Io\ConcurrentRead;
use Thesis\Io\IoException;
use Thesis\Io\Reader;

/**
 * @internal
 */
final class BodyReader implements Reader
{
    public private(set) ?IoException $failure = null;

    private bool $isReading = false;

    /**
     * @param non-negative-int $remaining
     */
    public function __construct(
        private readonly Reader $body,
        private int $remaining,
        private ?ContinueResponse $continue = null,
    ) {}

    public function read(int $max): ?string
    {
        if ($this->isReading) {
            throw new ConcurrentRead('A read is already in progress on the body.');
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->isReading = true;

        try {
            $this->sendContinue();

            return $this->readBody($max);
        } finally {
            $this->isReading = false;
        }
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
        try {
            if ($this->remaining === 0) {
                // The decoder must still be able to consume a final chunk and its trailers at the limit.
                if ($this->body->read(1) === null) {
                    return null;
                }

                throw new MalformedMessage('The request body exceeds its byte limit.', Status::CONTENT_TOO_LARGE);
            }

            $bytes = $this->body->read(min($max, $this->remaining));
        } catch (IoException $failure) {
            $this->failure = $failure;

            throw $failure;
        }

        if ($bytes === null) {
            return null;
        }

        /** @var non-negative-int $remaining a reader gives at most the bytes it is asked for */
        $remaining = $this->remaining - \strlen($bytes);
        $this->remaining = $remaining;

        return $bytes;
    }
}
