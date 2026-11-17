<?php

declare(strict_types=1);

namespace Thesis\Io\Internal;

use Revolt\EventLoop;
use Revolt\EventLoop\Driver\UvDriver;
use Thesis\Async\Cancelled;
use Thesis\Async\LocalMutex;
use Thesis\Async\Pacer;
use Thesis\Async\StreamWatcher;
use Thesis\Io\Closer;
use Thesis\Io\Reader;
use Thesis\Io\ReadFailed;
use Thesis\Io\StreamClosed;
use Thesis\Io\UnexpectedEof;
use Thesis\Io\WriteFailed;
use Thesis\Io\Writer;
use function Thesis\Time\ms;

/**
 * A stream over a descriptor whose open file description is shared with other processes: the standard ones.
 * `O_NONBLOCK` belongs to the description, so it would reach the shell, the jobs of the same terminal and
 * the children that inherit it, and they would get `EAGAIN`. So the mode is never touched: a read or a write
 * happens only once `stream_select()` says that it will not block, and a wait goes through the event loop.
 * The uv driver sets the mode on every descriptor it watches, so under uv a wait is a timer between selects.
 *
 * A read or a write that the select allowed may still block briefly: another process may take the bytes
 * or the room in between. A write passes at most {@see PIPE_BUF} bytes per call, the size a pipe that is
 * reported writable on Linux has room for.
 *
 * A write that fails or is cancelled after a part leaves the stream open: stdio has no messages to break,
 * and closing it would leave the process without its stdout.
 *
 * @internal
 */
final class SharedStream implements Reader, Writer, Closer
{
    private const int PIPE_BUF = 4096;

    /**
     * fread() allocates its whole length up front, so one read is bounded whatever the caller asks for.
     */
    private const int MAX_READ = 1024 * 1024;
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

    private bool $reading = false;

    private readonly LocalMutex $writeMutex;

    /**
     * @param resource $stream blocking, and it stays so
     */
    public function __construct(mixed $stream)
    {
        // A read goes straight to the descriptor: the select sees no bytes left in a PHP buffer.
        @stream_set_read_buffer($stream, 0);

        $this->stream = $stream;
        $this->watcher = new StreamWatcher($stream);
        $this->pacer = new Pacer(self::OPS_PER_TICK);
        $this->writeMutex = new LocalMutex();
    }

    /**
     * @param positive-int $max
     * @return non-empty-string
     * @throws UnexpectedEof
     * @throws ReadFailed
     * @throws StreamClosed
     * @throws Cancelled
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

                if (!self::ready($stream, write: false)) {
                    if (!$this->wait(write: false)) {
                        throw new StreamClosed('The stream was closed while reading.');
                    }

                    continue;
                }

                error_clear_last();
                $bytes = @fread($stream, min($max, self::MAX_READ));

                if ($bytes === false) {
                    throw new ReadFailed(lastError('Failed to read from the stream.'));
                }

                if ($bytes !== '') {
                    return $bytes;
                }

                if (feof($stream)) {
                    throw new UnexpectedEof('Unexpected end of stream.');
                }
            }
        } finally {
            $this->reading = false;
        }
    }

    /**
     * @throws WriteFailed
     * @throws StreamClosed
     * @throws Cancelled
     */
    public function write(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }

        $this->pacer->pace();
        $lock = $this->writeMutex->acquire();

        try {
            $length = \strlen($bytes);

            for ($written = 0; $written < $length; $written += $sent) {
                $stream = $this->stream ?? throw new StreamClosed('The stream was closed while writing.');
                $sent = 0;

                if (!self::ready($stream, write: true)) {
                    if (!$this->wait(write: true)) {
                        throw new StreamClosed('The stream was closed while writing.');
                    }

                    continue;
                }

                error_clear_last();
                $sent = @fwrite($stream, substr($bytes, $written, self::PIPE_BUF));

                if ($sent === false) {
                    throw new WriteFailed(lastError('Failed to write to the stream.'));
                }
            }
        } finally {
            $lock->release();
        }
    }

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
     * Waits until a read or a write may not block, false once the stream is closed.
     * `uv_poll_init()` makes the descriptor non-blocking until the process ends, so under the uv driver
     * the stream is never watched: it sleeps and the caller asks the select again.
     */
    private function wait(bool $write): bool
    {
        if (EventLoop::getDriver() instanceof UvDriver) {
            return $this->watcher->delay(ms(10));
        }

        return $write ? $this->watcher->writable() : $this->watcher->readable();
    }

    /**
     * Whether a read or a write would not block. A stream without a descriptor cannot be selected:
     * the call itself reports what is wrong with it then.
     *
     * @param resource $stream
     */
    private static function ready(mixed $stream, bool $write): bool
    {
        $read = $write ? [] : [$stream];
        $writes = $write ? [$stream] : [];
        $except = [];

        return @stream_select($read, $writes, $except, 0) !== 0;
    }
}
