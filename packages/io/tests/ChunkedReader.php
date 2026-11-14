<?php

declare(strict_types=1);

namespace Thesis\Io;

/**
 * Returns at most $chunkSize bytes per read and records requested sizes.
 */
final class ChunkedReader implements Reader
{
    /**
     * @var list<positive-int>
     */
    public private(set) array $requests = [];

    /**
     * @param positive-int $chunkSize
     */
    public function __construct(
        private string $data,
        private readonly int $chunkSize = PHP_INT_MAX,
    ) {}

    public function read(int $max): string
    {
        $this->requests[] = $max;

        if ($this->data === '') {
            throw new UnexpectedEof();
        }

        /** @var non-empty-string $bytes */
        $bytes = substr($this->data, 0, min($max, $this->chunkSize));
        $this->data = substr($this->data, \strlen($bytes));

        return $bytes;
    }
}
