<?php

declare(strict_types=1);

namespace Thesis\Http\Internal\Client;

use Thesis\Http\Headers;
use Thesis\Http\StreamingBody;
use Thesis\Io\Buffer;
use Thesis\Io\Reader;
use const Thesis\Http\EMPTY_HEADERS;

/**
 * @internal
 */
final class UntilCloseReader extends StreamingBody
{
    #[\Override]
    public ?int $size {
        get => null;
    }

    #[\Override]
    public bool $isBuffered {
        get => false;
    }

    #[\Override]
    public bool $mayHaveTrailers {
        get => false;
    }

    public function __construct(
        private readonly Buffer $buffer,
        private readonly Reader $source,
    ) {}

    private function __clone() {}

    protected function readPayload(int $max): ?string
    {
        $available = $this->buffer->count();

        return $available > 0 ? $this->buffer->read(min($available, $max)) : $this->source->read($max);
    }

    public function trailers(): Headers
    {
        return EMPTY_HEADERS;
    }

    public function close(): void {}
}
