<?php

declare(strict_types=1);

namespace Thesis\HttpProtocol\Http1;

use Thesis\Http\Body;
use Thesis\Http\Headers;
use Thesis\Io\Buffer;
use Thesis\Io\ConcurrentRead;
use Thesis\Io\Reader;
use Thesis\Io\ReadFailed;
use Thesis\Io\UnexpectedEof;
use const Thesis\Http\EMPTY_HEADERS;

/**
 * Reads a body of exactly the given size from a connection. The connection owner keeps the buffer
 * and any bytes left in it for the next message. Closing the body leaves the connection open.
 *
 * @api
 */
final class FixedLengthReader implements Body
{
    #[\Override]
    public bool $isBuffered {
        get => false;
    }

    #[\Override]
    public bool $mayHaveTrailers {
        get => false;
    }

    /**
     * @var non-negative-int
     */
    public private(set) int $remaining;

    private bool $isReading = false;

    /**
     * @param non-negative-int $size the total number of body bytes
     */
    public function __construct(
        private readonly Buffer $buffer,
        private readonly Reader $reader,
        #[\Override]
        public readonly int $size,
    ) {
        $this->remaining = $size;
    }

    private function __clone() {}

    public function read(int $max): ?string
    {
        if ($this->isReading) {
            throw new ConcurrentRead('A read is already in progress on the body.');
        }

        if ($this->remaining === 0) {
            return null;
        }

        $length = min($max, $this->remaining);
        $buffered = $this->buffer->count();
        $this->isReading = true;

        try {
            $bytes = $buffered > 0 ? $this->buffer->read(min($length, $buffered)) : $this->reader->read($length);
        } finally {
            $this->isReading = false;
        }

        if ($bytes === null) {
            $received = $this->size - $this->remaining;

            throw new ReadFailed(
                message: \sprintf('The body has ended after %d of %d bytes.', $received, $this->size),
                previous: new UnexpectedEof(),
            );
        }

        /** @var non-negative-int $remaining a reader gives at most the bytes it is asked for */
        $remaining = $this->remaining - \strlen($bytes);
        $this->remaining = $remaining;

        return $bytes;
    }

    public function discard(): Headers
    {
        while ($this->read(8192) !== null);

        return EMPTY_HEADERS;
    }

    public function close(): void {}
}
