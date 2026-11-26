<?php

declare(strict_types=1);

namespace Thesis\Http;

use Thesis\Io\ReadFailed;
use Thesis\Io\Stream;
use Thesis\Io\StreamClosed;
use function Thesis\Http\Internal\lastError;

/**
 * Streams a local file, with the size it had when opened.
 *
 * @api
 */
final class FileBody extends StreamingBody
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
    #[\Override]
    public readonly int $size;

    private readonly Stream $file;

    private int $remaining;

    /**
     * @throws ReadFailed if the path cannot be opened as a regular file or its size cannot be read
     */
    public function __construct(string $path)
    {
        error_clear_last();
        $file = @realpath($path);
        if ($file === false || !@is_file($file)) {
            throw new ReadFailed(lastError("Not a regular file: '{$path}'."));
        }
        error_clear_last();
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            throw new ReadFailed(lastError("Cannot open file '{$path}'."));
        }
        error_clear_last();
        $stat = @fstat($handle);
        if ($stat === false || $stat['size'] < 0) {
            $message = lastError("Cannot determine the size of file '{$path}'.");
            @fclose($handle);

            throw new ReadFailed($message);
        }

        $this->file = new Stream($handle);
        $this->size = $stat['size'];
        $this->remaining = $this->size;
    }

    private function __clone() {}

    protected function readPayload(int $max): ?string
    {
        if ($this->file->isClosed) {
            throw new StreamClosed('The file body is closed.');
        }
        if ($this->remaining <= 0) {
            return null;
        }

        $bytes = $this->file->read(min($max, $this->remaining));
        if ($bytes === null) {
            throw new ReadFailed('The file ended before its declared length.');
        }
        $this->remaining -= \strlen($bytes);

        return $bytes;
    }

    public function trailers(): Headers
    {
        return EMPTY_HEADERS;
    }

    public function close(): void
    {
        $this->file->close();
    }
}
