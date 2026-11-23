<?php

declare(strict_types=1);

namespace Thesis\Http\Http1;

use Thesis\Io\Buffer;
use Thesis\Io\ConcurrentRead;
use Thesis\Io\Reader;
use Thesis\Io\ReadFailed;
use Thesis\Io\SizedReader;
use Thesis\Io\UnexpectedEof;

/**
 * Reads a body of exactly the given size from a connection. The connection owner keeps the buffer
 * and any bytes left in it for the next message.
 *
 * @api
 */
final class FixedLengthReader implements SizedReader
{
    /**
     * @var non-negative-int
     */
    private int $remaining;

    private bool $isReading = false;

    /**
     * @param non-negative-int $size the total number of body bytes
     */
    public function __construct(
        private readonly Buffer $buffer,
        private readonly Reader $reader,
        public readonly int $size,
    ) {
        $this->remaining = $size;
    }

    public function read(int $max): string
    {
        if ($this->isReading) {
            throw new ConcurrentRead('A read is already in progress on the body.');
        }

        if ($this->remaining === 0) {
            throw new UnexpectedEof();
        }

        $length = min($max, $this->remaining);
        $buffered = $this->buffer->count();
        $this->isReading = true;

        try {
            $bytes = $buffered > 0 ? $this->buffer->read(min($length, $buffered)) : $this->reader->read($length);
        } catch (UnexpectedEof $eof) {
            $received = $this->size - $this->remaining;

            throw new ReadFailed(
                message: \sprintf('The body has ended after %d of %d bytes.', $received, $this->size),
                previous: $eof,
            );
        } finally {
            $this->isReading = false;
        }

        /** @var non-negative-int $remaining a reader gives at most the bytes it is asked for */
        $remaining = $this->remaining - \strlen($bytes);
        $this->remaining = $remaining;

        return $bytes;
    }
}
