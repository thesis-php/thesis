<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Stub;

use Thesis\Http\Headers;
use Thesis\Http\StreamingBody;
use Thesis\Io\Reader;
use const Thesis\Http\EMPTY_HEADERS;

final class ReaderBody extends StreamingBody
{
    #[\Override]
    public bool $isBuffered = true;

    #[\Override]
    public bool $mayHaveTrailers {
        get => false;
    }

    /**
     * @param ?non-negative-int $size
     */
    public function __construct(
        private readonly Reader $reader,
        #[\Override]
        public readonly ?int $size,
    ) {}

    protected function readPayload(int $max): ?string
    {
        return $this->reader->read($max);
    }

    public function trailers(): Headers
    {
        return EMPTY_HEADERS;
    }

    public function close(): void {}
}
