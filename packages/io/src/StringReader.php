<?php

declare(strict_types=1);

namespace Thesis\Io;

/**
 * Reads bytes from a string in memory without suspending the fiber.
 *
 * @api
 */
final class StringReader implements SizedReader
{
    public readonly int $size;

    private int $offset = 0;

    public function __construct(
        private readonly string $bytes,
    ) {
        $this->size = \strlen($bytes);
    }

    public function read(int $max): string
    {
        $bytes = substr($this->bytes, $this->offset, $max);

        if ($bytes === '') {
            throw new UnexpectedEof('Unexpected end of string.');
        }

        $this->offset += \strlen($bytes);

        return $bytes;
    }
}
