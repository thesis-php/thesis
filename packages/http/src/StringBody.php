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

    public function readAll(): string
    {
        if ($this->offset !== 0) {
            throw new \LogicException('The body has already been read.');
        }

        $this->offset = \strlen($this->bytes);

        return $this->bytes;
    }

    public function close(): void {}
}
