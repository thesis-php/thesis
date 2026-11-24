<?php

declare(strict_types=1);

namespace Thesis\Http;

/**
 * @api
 */
final class StringBody implements Body
{
    public int $size {
        get => \strlen($this->bytes);
    }

    public bool $isBuffered {
        get => true;
    }

    public bool $mayHaveTrailers {
        get => !$this->trailers->isEmpty;
    }

    private int $offset = 0;

    public function __construct(
        private readonly string $bytes,
        private readonly Headers $trailers = EMPTY_HEADERS,
    ) {}

    private function __clone() {}

    /**
     * Returns an independent reader at the beginning, including the original trailers.
     */
    public function fresh(): self
    {
        return new self($this->bytes, $this->trailers);
    }

    public function read(int $max): ?string
    {
        $bytes = substr($this->bytes, $this->offset, $max);
        if ($bytes === '') {
            return null;
        }

        $this->offset += \strlen($bytes);

        return $bytes;
    }

    public function discard(): Headers
    {
        $this->offset = \strlen($this->bytes);

        return $this->trailers;
    }

    public function close(): void {}
}
