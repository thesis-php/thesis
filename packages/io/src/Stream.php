<?php

declare(strict_types=1);

namespace Thesis\Io;

use Thesis\Async\Cancelled;
use Thesis\Async\LocalMutex;
use Thesis\Async\Pacer;
use Thesis\Async\StreamWatcher;

/**
 * {@see Reader}, {@see Writer} and {@see Closer} over a PHP stream resource: a pipe, a terminal, a file,
 * a socket without TLS. The stream is made non-blocking, and every wait is a wait of thesis/async:
 * cancelled with the current scope and bounded by `timeout()`. The stream is owned: {@see close()} closes it.
 *
 * One fiber reads at a time, any number may write: a write is never interleaved with another one.
 *
 * @api
 */
final class Stream implements Reader, Writer, Closer
{
    /**
     * Bytes passed to one fwrite() after a partial write, so that a large write is not copied for every syscall.
     */
    private const int WRITE_CHUNK = 1_048_576;

    /**
     * Reads that find bytes at once before one yields, so that a writer without a pause cannot hold the event loop.
     */
    private const int READS_PER_TICK = 16;

    /**
     * A stream whose open file description is shared with other processes: the standard descriptors.
     * It stays blocking and is made non-blocking only for the time of each read and write, since the mode
     * belongs to the description and would reach every process that shares it (a job of the same terminal,
     * a child that inherits it). A wait for readiness does not need the mode: the event loop only polls.
     *
     * @internal for stdio
     * @param resource $stream
     */
    public static function shared(mixed $stream): self
    {
        $self = new self($stream);
        stream_set_blocking($stream, true);
        $self->shared = true;

        return $self;
    }

    public bool $isClosed {
        get => $this->stream === null;
    }

    /**
     * @var ?resource
     */
    private mixed $stream;

    private readonly StreamWatcher $watcher;

    private readonly Pacer $pacer;

    /**
     * Whether a read waits: a second reader is a bug, the two would take bytes from each other.
     */
    private bool $reading = false;

    /**
     * Fibers inside a write that has to wait, holding or waiting for {@see $writeMutex}.
     * While there are none, a write goes to the stream without the mutex.
     */
    private int $writers = 0;

    private ?LocalMutex $writeMutex = null;

    /**
     * Whether the stream is blocking between calls, see {@see shared()}.
     */
    private bool $shared = false;

    /**
     * @param resource $stream
     * @param positive-int $chunkSize max number of bytes a single {@see read()} may return.
     *        PHP limits reads from sockets to 8 KiB by default, which is too small for {@see Buffer::fill()}.
     */
    public function __construct(mixed $stream, int $chunkSize = 65_536)
    {
        if (!\is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new \InvalidArgumentException(\sprintf(
                'Expected a stream resource, got %s.',
                get_debug_type($stream),
            ));
        }

        // Streams without a file descriptor (php://memory) cannot be non-blocking, and they never have to wait.
        @stream_set_blocking($stream, false);
        // A read goes straight to the descriptor:
        // no bytes are left in a PHP buffer where the event loop does not see them.
        @stream_set_read_buffer($stream, 0);
        stream_set_chunk_size($stream, $chunkSize);

        $this->stream = $stream;
        $this->watcher = new StreamWatcher($stream);
        $this->pacer = new Pacer(self::READS_PER_TICK);
    }

    /**
     * Returns the bytes that have arrived, waiting only if there are none. A read that throws {@see Cancelled}
     * has taken nothing.
     *
     * @param positive-int $max
     * @return non-empty-string
     * @throws UnexpectedEof once the other side has closed the stream and every byte has been read
     * @throws ReadFailed
     * @throws StreamClosed if this side has closed the stream, during the wait too
     * @throws \LogicException if another fiber is waiting in read()
     */
    public function read(int $max): string
    {
        $this->pacer->pace();

        while (true) {
            $stream = $this->stream ?? throw new StreamClosed('The stream is closed.');

            // Read first, wait only if there is nothing: a regular file or php://memory never has to wait,
            // and the event loop cannot even watch them.
            error_clear_last();
            $bytes = $this->tryRead($stream, $max);

            if ($bytes !== '' && $bytes !== false) {
                return $bytes;
            }

            if ($bytes === false) {
                throw new ReadFailed(self::lastError('Failed to read from the stream.'));
            }

            if (feof($stream)) {
                throw new UnexpectedEof('Unexpected end of stream.');
            }

            if ($this->reading) {
                throw new \LogicException('Another fiber is reading from the stream.');
            }

            $this->reading = true;

            try {
                $readable = $this->watcher->readable();
            } finally {
                $this->reading = false;
            }

            if (!$readable) {
                throw new StreamClosed('The stream was closed while reading.');
            }
        }
    }

    /**
     * Returns once every byte is handed to the OS, waiting while its buffer is full.
     * Concurrent writes go one after another, in the order they came.
     *
     * If a write fails or is cancelled after a part of its bytes has been written, the stream is closed:
     * the other side has got half a message. A write cancelled before writing anything leaves the stream as it was.
     *
     * @throws WriteFailed
     * @throws StreamClosed if this side has closed the stream, during the wait too
     */
    public function write(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }

        $written = 0;

        if ($this->writers === 0) {
            $stream = $this->stream ?? throw new StreamClosed('The stream is closed.');
            error_clear_last();
            $written = $this->tryWrite($stream, $bytes);

            if ($written === \strlen($bytes)) {
                return;
            }

            if ($written === false || ($written === 0 && error_get_last() !== null)) {
                throw new WriteFailed(self::lastError('Failed to write to the stream.'));
            }
        }

        $this->finishWrite($bytes, $written);
    }

    /**
     * Closes the stream at once. Reads and writes waiting in other fibers throw {@see StreamClosed}. Idempotent.
     */
    public function close(): void
    {
        $stream = $this->stream;

        if ($stream === null) {
            return;
        }

        $this->stream = null;
        $this->watcher->close();
        @fclose($stream);
    }

    /**
     * @param non-empty-string $default
     * @return non-empty-string
     */
    private static function lastError(string $default): string
    {
        $message = error_get_last()['message'] ?? '';
        $message = preg_replace('/^\w+\(\): /', '', $message) ?? $message;

        return $message === '' ? $default : $message;
    }

    /**
     * fread() that does not block, whatever the mode of a {@see shared()} stream.
     *
     * @param resource $stream
     * @param positive-int $max
     */
    private function tryRead(mixed $stream, int $max): string|false
    {
        if (!$this->shared) {
            return @fread($stream, $max);
        }

        stream_set_blocking($stream, false);

        try {
            return @fread($stream, $max);
        } finally {
            stream_set_blocking($stream, true);
        }
    }

    /**
     * fwrite() that does not block, whatever the mode of a {@see shared()} stream.
     *
     * @param resource $stream
     */
    private function tryWrite(mixed $stream, string $bytes): int|false
    {
        if (!$this->shared) {
            return @fwrite($stream, $bytes);
        }

        stream_set_blocking($stream, false);

        try {
            return @fwrite($stream, $bytes);
        } finally {
            stream_set_blocking($stream, true);
        }
    }

    /**
     * @param int $written by the fast path, which a writer only takes while there are no others:
     *     the mutex is then free and is taken without a wait, before another write can start
     */
    private function finishWrite(string $bytes, int $written): void
    {
        $length = \strlen($bytes);
        ++$this->writers;

        try {
            $lock = ($this->writeMutex ??= new LocalMutex())->acquire();

            try {
                while ($written < $length) {
                    $stream = $this->stream ?? throw new StreamClosed('The stream was closed while writing.');
                    error_clear_last();
                    $n = $this->tryWrite($stream, substr($bytes, $written, self::WRITE_CHUNK));

                    if ($n === false || ($n === 0 && error_get_last() !== null)) {
                        throw new WriteFailed(self::lastError('Failed to write to the stream.'));
                    }

                    if ($n > 0) {
                        $written += $n;
                    } elseif (!$this->watcher->writable()) {
                        throw new StreamClosed('The stream was closed while writing.');
                    }
                }
            } finally {
                $lock->release();
            }
        } catch (\Throwable $error) {
            if ($written > 0) {
                $this->close();
            }

            throw $error;
        } finally {
            --$this->writers;
        }
    }
}
