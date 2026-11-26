<?php

declare(strict_types=1);

namespace Thesis\Http;

use Thesis\Io\ConcurrentRead;

/**
 * Implements exclusive reads and whole-payload reads for streaming bodies.
 *
 * @api
 */
abstract class StreamingBody implements Body
{
    private bool $reading = false;

    private bool $consumed = false;

    final public function read(int $max): ?string
    {
        $this->beginRead();

        try {
            $bytes = $this->readPayload($max);
            if ($bytes !== null) {
                $this->consumed = true;
            }

            return $bytes;
        } finally {
            $this->reading = false;
        }
    }

    final public function discard(): Headers
    {
        $this->beginRead();

        try {
            while ($this->readPayload(8192) !== null) {
                $this->consumed = true;
            }

            return $this->trailers();
        } catch (\Throwable $error) {
            $this->close();

            throw $error;
        } finally {
            $this->reading = false;
        }
    }

    final public function readAll(int $limit = self::DEFAULT_BUFFER_LIMIT): string
    {
        $this->beginRead(whole: true);

        try {
            $payload = '';

            while (($bytes = $this->readPayload(8192)) !== null) {
                $this->consumed = true;
                if (\strlen($bytes) > $limit - \strlen($payload)) {
                    throw new \OverflowException("The body exceeds {$limit} bytes.");
                }

                $payload .= $bytes;
            }

            return $payload;
        } catch (\Throwable $error) {
            $this->close();

            throw $error;
        } finally {
            $this->reading = false;
        }
    }

    /**
     * @param positive-int $max
     * @return ?non-empty-string
     */
    abstract protected function readPayload(int $max): ?string;

    private function beginRead(bool $whole = false): void
    {
        if ($this->reading) {
            throw new ConcurrentRead('A read is already in progress on the body.');
        }

        if ($whole && $this->consumed) {
            throw new \LogicException('The body has already been read.');
        }

        $this->reading = true;
    }
}
