<?php

declare(strict_types=1);

namespace Thesis\Http;

/**
 * @api
 */
final class StringBody implements ReplayableBody
{
    #[\Override]
    public int $size {
        get => \strlen($this->bytes);
    }

    #[\Override]
    public bool $isBuffered {
        get => true;
    }

    #[\Override]
    public bool $mayHaveTrailers {
        get => !$this->trailers->isEmpty;
    }

    private int $offset = 0;

    public function __construct(
        private readonly string $bytes,
        private readonly Headers $trailers = EMPTY_HEADERS,
    ) {}

    private function __clone() {}

    public function trailers(): Headers
    {
        return $this->trailers;
    }

    public function fresh(): self
    {
        if ($this->bytes === '' && $this->trailers->isEmpty) {
            return EMPTY_BODY;
        }

        return clone($this, ['offset' => 0]);
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

    public function readAll(int $limit = self::DEFAULT_BUFFER_LIMIT): string
    {
        if ($this->offset !== 0) {
            throw new \LogicException('The body has already been read.');
        }

        if ($this->size > $limit) {
            throw new \OverflowException("The body exceeds {$limit} bytes.");
        }

        $this->offset = \strlen($this->bytes);

        return $this->bytes;
    }

    public function close(): void {}
}
