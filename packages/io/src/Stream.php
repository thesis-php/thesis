<?php

declare(strict_types=1);

namespace Thesis\Io;

use Thesis\Async\Channel;
use Thesis\Async\Pacer;
use Thesis\Async\SendResult;
use Thesis\Async\StreamWatcher;
use function Thesis\Io\Internal\lastError;

/**
 * {@see Reader}, {@see Writer} and {@see Closer} over a PHP stream resource owned by this process: a pipe
 * of `proc_open()`, a file, a socket without TLS. The stream is made non-blocking, and every wait is a wait of thesis/async:
 * cancelled with the current scope and bounded by `timeout()`. The stream is owned: {@see close()} closes it.
 *
 * Several fibers may write while a read is pending. Writes never interleave.
 *
 * @api
 */
final class Stream implements Reader, Writer, Closer
{
    /**
     * Bytes passed to one fwrite() after a partial write: about what a pipe takes at once,
     * so that a large write is neither copied whole for every syscall nor split into many.
     */
    private const int WRITE_CHUNK = 64 * 1024;

    /**
     * fread() allocates its whole length up front, so one read is bounded whatever the caller asks for:
     * a length from a header must not cost its size in memory before a byte has arrived.
     */
    private const int MAX_READ = 1024 * 1024;

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
     * Held even while the pacer yields, so a later read cannot take bytes meant for the pending read.
     */
    private bool $reading = false;

    private bool $writing = false;

    /**
     * Only allocated on contention. A rendezvous hands ownership directly to the first waiting writer.
     *
     * @var ?Channel<true>
     */
    private ?Channel $waitingWriters = null;

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

    public function read(int $max): string
    {
        if ($this->reading) {
            throw new ConcurrentRead('A read is already in progress on the stream.');
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
     * Returns once every byte is handed to the OS.
     * Concurrent writes go one after another, in the order they came.
     */
    public function write(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }

        if ($this->stream === null) {
            throw new StreamClosed('The stream is closed.');
        }

        if ($this->writing) {
            ($this->waitingWriters ??= new Channel(0))->receive();
        }

        $this->writing = true;
        $chunk = $bytes;
        $length = \strlen($bytes);
        $written = 0;

        try {
            $this->pacer->pace();

            while (true) {
                $stream = $this->stream ?? throw new StreamClosed('The stream was closed while writing.');
                error_clear_last();
                $sent = @fwrite($stream, $chunk);

                if ($sent === false) {
                    throw new WriteFailed(lastError('Failed to write to the stream.'));
                }

                $written += $sent;

                if ($written === $length) {
                    return;
                }

                if ($sent === 0 && !$this->watcher->writable()) {
                    throw new StreamClosed('The stream was closed while writing.');
                }

                $chunk = substr($bytes, $written, self::WRITE_CHUNK);
            }
        } catch (\Throwable $error) {
            if ($written > 0) {
                $this->close();
            }

            throw $error;
        } finally {
            $this->writing = $this->waitingWriters?->trySend(true) === SendResult::Sent;
        }
    }

    /**
     * Closes the stream immediately.
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
}
