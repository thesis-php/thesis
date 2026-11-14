<?php

declare(strict_types=1);

namespace Thesis\Io;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Returns pseudo-random chunks of 1 to 97 bytes, the same for the same seed and the same requests.
 */
final class FuzzReader implements Reader
{
    private readonly Randomizer $random;

    public function __construct(
        private string $data,
        int $seed,
    ) {
        $this->random = new Randomizer(new Mt19937($seed));
    }

    public function read(int $max): string
    {
        if ($this->data === '') {
            throw new UnexpectedEof('Fuzz reader is exhausted.');
        }

        /** @var non-empty-string $bytes */
        $bytes = substr($this->data, 0, min($max, $this->random->getInt(1, 97)));
        $this->data = substr($this->data, \strlen($bytes));

        return $bytes;
    }
}
