<?php

declare(strict_types=1);

namespace Thesis\Io;

/**
 * Reads bytes from a string in memory without suspending the fiber.
 *
 * @api
 */
final class StringReader implements Reader
{
    /**
     * @var non-negative-int
     */
    public int $size {
        get => \strlen($this->bytes);
    }

    private int $offset = 0;

    public function __construct(
        private readonly string $bytes,
    ) {}

    public function read(int $max): ?string
    {
        $bytes = substr($this->bytes, $this->offset, $max);

        if ($bytes === '') {
            return null;
        }

        $this->offset += \strlen($bytes);

        return $bytes;
    }
}
