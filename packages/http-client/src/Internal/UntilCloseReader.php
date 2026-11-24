<?php

declare(strict_types=1);

namespace Thesis\HttpClient\Internal;

use Thesis\Http\AbstractBody;
use Thesis\Http\Headers;
use Thesis\Io\Buffer;
use Thesis\Io\Reader;
use const Thesis\Http\EMPTY_HEADERS;

/**
 * @internal
 */
final class UntilCloseReader extends AbstractBody
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

    public function read(int $max): ?string
    {
        $available = $this->buffer->count();

        return $available > 0 ? $this->buffer->read(min($available, $max)) : $this->source->read($max);
    }

    public function discard(): Headers
    {
        while ($this->read(8192) !== null);

        return EMPTY_HEADERS;
    }

    public function close(): void {}
}
