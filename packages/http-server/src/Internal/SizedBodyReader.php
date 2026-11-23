<?php

declare(strict_types=1);

namespace Thesis\HttpServer\Internal;

use Thesis\Io\SizedReader;

/**
 * @internal
 */
final readonly class SizedBodyReader implements SizedReader
{
    /**
     * @param non-negative-int $size
     */
    public function __construct(
        private BodyReader $reader,
        public int $size,
    ) {}

    public function read(int $max): string
    {
        return $this->reader->read($max);
    }
}
