<?php

declare(strict_types=1);

namespace Thesis\Io;

use Thesis\Async\Cancelled;
use Thesis\Async\LocalMutex;
use Thesis\Async\Pacer;
use Thesis\Async\StreamWatcher;
use function Thesis\Io\Internal\lastError;

/**
 * {@see Reader}, {@see Writer} and {@see Closer} over a PHP stream resource owned by this process: a pipe
 * of `proc_open()`, a file, a socket without TLS. The stream is made non-blocking, and every wait is a wait of thesis/async:
 * cancelled with the current scope and bounded by `timeout()`. The stream is owned: {@see close()} closes it.
 *
 * One fiber reads at a time, any number may write: a write is never interleaved with another one.
 *
 * @api
 */
final class Stream implements Reader, Writer, Closer
{
    /**
     * Bytes passed to one fwrite() after a partial write: about what a pipe takes at once,
     * so that a large write is neither copied whole for every syscall nor split into many.
     */
    private const int WRITE_CHUNK = 65_536;

    /**
     * fread() allocates its whole length up front, so one read is bounded whatever the caller asks for:
     * a length from a header must not cost its size in memory before a byte has arrived.
     */
    private const int MAX_READ = 1_048_576;

    /**
     * Reads and writes that do not wait before one yields: a file, /dev/null or a fast peer never makes them wait,
     * and they must not hold the event loop.
     */
    private const int OPS_PER_TICK = 16;

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
     * Whether a read is in progress: a second reader is a bug, the two would take bytes from each other.
     */
    private bool $reading = false;

    /**
     * Fibers inside a write that has to wait, holding or waiting for {@see $writeMutex}.
     * While there are none, a write goes to the stream without the mutex.
     */
    private int $writers = 0;

    private ?LocalMutex $writeMutex = null;

    /**
     * @param resource $stream
     */
    public function __construct(mixed $stream)
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

        $this->stream = $stream;
        $this->watcher = new StreamWatcher($stream);
        $this->pacer = new Pacer(self::OPS_PER_TICK);
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
     * @throws \LogicException if another fiber is reading
     */
    public function read(int $max): string
    {
        if ($this->reading) {
            throw new \LogicException('Another fiber is reading from the stream.');
        }

        $this->reading = true;

        try {
            $this->pacer->pace();

            while (true) {
                $stream = $this->stream ?? throw new StreamClosed('The stream is closed.');

                // Read first, wait only if there is nothing: a regular file or php://memory never has to wait,
                // and the event loop cannot even watch them.
                // A failed fread() or fwrite() of a stream of the OS reports its own warning,
                // which replaces the last error.
                // @infection-ignore FunctionCallRemoval
                error_clear_last();
                $bytes = @fread($stream, min($max, self::MAX_READ));

                if ($bytes !== '' && $bytes !== false) {
                    return $bytes;
                }

                if ($bytes === false) {
                    throw new ReadFailed(lastError('Failed to read from the stream.'));
                }

                if (feof($stream)) {
                    throw new UnexpectedEof('Unexpected end of stream.');
                }

                if (!$this->watcher->readable()) {
                    throw new StreamClosed('The stream was closed while reading.');
                }
            }
        } finally {
            $this->reading = false;
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

        $this->pacer->pace();
        $written = 0;

        if ($this->writers === 0) {
            $stream = $this->stream ?? throw new StreamClosed('The stream is closed.');
            // the last error is replaced anyway, see read()
            // @infection-ignore FunctionCallRemoval
            error_clear_last();
            $written = @fwrite($stream, $bytes);

            if ($written === \strlen($bytes)) {
                // Without it the write takes the lock and writes nothing more.
                // @infection-ignore ReturnRemoval
                return;
            }

            if ($written === false) {
                throw new WriteFailed(lastError('Failed to write to the stream.'));
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
     * @param int $written by the fast path, which a writer only takes while there are no others:
     *     the mutex is then free and is taken without a wait, before another write can start
     */
    private function finishWrite(string $bytes, int $written): void
    {
        $length = \strlen($bytes);
        // The number of writers only decides whether a write may take the fast path without the lock:
        // a write that takes the lock instead is slower, with the same bytes in the same order.
        // @infection-ignore Increment
        ++$this->writers;

        // the number of writers, see above
        // @infection-ignore Finally_, UnwrapFinally
        try {
            // released by its destructor once the method returns or throws
            $lock = ($this->writeMutex ??= new LocalMutex())->acquire();

            while ($written < $length) {
                $stream = $this->stream ?? throw new StreamClosed('The stream was closed while writing.');
                // the last error is replaced anyway, see read()
                // @infection-ignore FunctionCallRemoval
                error_clear_last();
                $sent = @fwrite($stream, substr($bytes, $written, self::WRITE_CHUNK));

                if ($sent === false) {
                    throw new WriteFailed(lastError('Failed to write to the stream.'));
                }

                if ($sent > 0) {
                    $written += $sent;
                } elseif (!$this->watcher->writable()) {
                    // The watcher ends a wait with false only after close(), so the loop would throw the same
                    // at its start: the throw says it where the wait ends, as in read().
                    // @infection-ignore Throw_
                    throw new StreamClosed('The stream was closed while writing.');
                }
            }
        } catch (\Throwable $error) {
            if ($written > 0) {
                $this->close();
            }

            throw $error;
        } finally {
            // the number of writers, see above
            // @infection-ignore Decrement
            --$this->writers;
        }
    }
}
