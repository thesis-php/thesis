<?php

declare(strict_types=1);

namespace Thesis\Io;

/**
 * {@see Reader}, {@see Writer} and {@see Closer} over a blocking PHP stream resource.
 * The stream is owned: {@see close()} closes it.
 *
 * @api
 */
final readonly class Stream implements Reader, Writer, Closer
{
    /**
     * @param resource $stream
     */
    public function __construct(
        private mixed $stream,
    ) {
        if (!\is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new \InvalidArgumentException(\sprintf('Expected a stream resource, got %s.', get_debug_type($stream)));
        }
    }

    public function read(int $max): string
    {
        $bytes = @fread($this->stream, $max);

        if ($bytes === false || $bytes === '') {
            throw new UnexpectedEof(error_get_last()['message'] ?? 'Unexpected end of stream.');
        }

        return $bytes;
    }

    public function write(string $bytes): void
    {
        $length = \strlen($bytes);

        for ($written = 0; $written < $length; $written += $n) {
            $n = @fwrite($this->stream, $written === 0 ? $bytes : substr($bytes, $written));

            if ($n === false || $n === 0) {
                throw new WriteFailed(error_get_last()['message'] ?? 'Failed to write to stream.');
            }
        }
    }

    public function close(): void
    {
        if (\is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
