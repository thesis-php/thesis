<?php

declare(strict_types=1);

namespace Thesis\Http\Http1;

use Thesis\Io\Closer;
use Thesis\Io\ConcurrentRead;
use Thesis\Io\Reader;
use Thesis\Io\ReadFailed;

/**
 * The reader returns its bytes at most $chunkSize per read. Records the size every read asked for and the number of
 * closes.
 */
final class ScriptedReader implements Reader, Closer
{
    /**
     * @var list<positive-int>
     */
    public private(set) array $requests = [];

    private bool $isReading = false;

    public private(set) int $closes = 0;

    /**
     * The next read throws this once and reads nothing.
     */
    public ?\Throwable $failure = null;

    /**
     * The first read and every second read after it throw ReadFailed and read nothing.
     */
    public bool $failsEveryOtherRead = false;

    /**
     * @var ?\Closure(): void
     */
    public ?\Closure $beforeRead = null;

    /**
     * @param positive-int $chunkSize
     */
    public function __construct(
        private string $bytes,
        private readonly int $chunkSize = PHP_INT_MAX,
    ) {}

    public function read(int $max): ?string
    {
        if ($this->isReading) {
            throw new ConcurrentRead('A read is already in progress on the reader.');
        }

        $this->requests[] = $max;
        if ($this->beforeRead !== null) {
            $this->isReading = true;

            try {
                ($this->beforeRead)();
            } finally {
                $this->isReading = false;
            }
        }

        if ($this->failure !== null) {
            $failure = $this->failure;
            $this->failure = null;

            throw $failure;
        }

        if ($this->failsEveryOtherRead && \count($this->requests) % 2 === 1) {
            throw new ReadFailed('Reset.');
        }

        if ($this->bytes === '') {
            return null;
        }

        /** @var non-empty-string $bytes */
        $bytes = substr($this->bytes, 0, min($max, $this->chunkSize));
        $this->bytes = substr($this->bytes, \strlen($bytes));

        return $bytes;
    }

    public function close(): void
    {
        ++$this->closes;
    }
}
