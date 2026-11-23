<?php

declare(strict_types=1);

namespace Thesis\HttpServer\Stub;

use Thesis\Io\Reader;

final class PrefixedReader implements Reader
{
    private bool $hasPrefix = true;

    public function __construct(
        private readonly Reader $reader,
    ) {}

    public function read(int $max): string
    {
        if ($this->hasPrefix) {
            $this->hasPrefix = false;

            return '!';
        }

        return $this->reader->read($max);
    }
}
