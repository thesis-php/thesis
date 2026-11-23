<?php

declare(strict_types=1);

namespace Thesis\HttpClient\Internal;

use Thesis\Io\Buffer;
use Thesis\Io\Reader;

/**
 * @internal
 */
final readonly class UntilCloseReader implements Reader
{
    public function __construct(
        private Buffer $buffer,
        private Reader $source,
    ) {}

    public function read(int $max): string
    {
        $available = $this->buffer->count();

        return $available > 0 ? $this->buffer->read(min($available, $max)) : $this->source->read($max);
    }
}
