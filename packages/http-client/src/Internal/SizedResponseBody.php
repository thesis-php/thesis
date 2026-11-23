<?php

declare(strict_types=1);

namespace Thesis\HttpClient\Internal;

use Thesis\Io\Closer;
use Thesis\Io\SizedReader;

/**
 * @internal
 */
final readonly class SizedResponseBody implements SizedReader, Closer
{
    /**
     * @param non-negative-int $size
     */
    public function __construct(
        private ResponseBody $body,
        public int $size,
    ) {}

    public function close(): void
    {
        $this->body->close();
    }

    public function read(int $max): string
    {
        return $this->body->read($max);
    }
}
