<?php

declare(strict_types=1);

namespace Thesis\Io;

/**
 * Calls $meanwhile before the first read: what another fiber does while a read waits.
 * Returns at most $chunkSize bytes per read.
 */
final class CallbackReader implements Reader
{
    private bool $reading = false;

    /**
     * @param ?\Closure(): void $meanwhile
     * @param positive-int $chunkSize
     */
    public function __construct(
        private string $data,
        private ?\Closure $meanwhile,
        private readonly int $chunkSize = 1,
    ) {}

    public function read(int $max): ?string
    {
        if ($this->reading) {
            throw new ConcurrentRead('A read is already in progress on the reader.');
        }

        $meanwhile = $this->meanwhile;
        $this->meanwhile = null;

        if ($meanwhile !== null) {
            $this->reading = true;

            try {
                $meanwhile();
            } finally {
                $this->reading = false;
            }
        }

        if ($this->data === '') {
            return null;
        }

        /** @var non-empty-string $bytes */
        $bytes = substr($this->data, 0, min($max, $this->chunkSize));
        $this->data = substr($this->data, \strlen($bytes));

        return $bytes;
    }
}
