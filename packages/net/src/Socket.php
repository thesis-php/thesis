<?php

declare(strict_types=1);

namespace Thesis\Net;

use Thesis\Async\Cancelled;
use Thesis\Async\LocalMutex;
use Thesis\Async\Pacer;
use Thesis\Async\StreamWatcher;
use Thesis\Io\Closer;
use Thesis\Io\Reader;
use Thesis\Io\ReadFailed;
use Thesis\Io\UnexpectedEof;
use Thesis\Io\WriteFailed;
use Thesis\Io\Writer;
use function Thesis\Net\Internal\address;
use function Thesis\Net\Internal\lastError;

/**
 * A connected stream socket: TCP or Unix, plain or TLS. Every wait is a wait of thesis/async:
 * cancelled with the current scope and bounded by `timeout()`, so no method takes a timeout.
 *
 * One fiber reads at a time, any number may write: a write is never interleaved with another one.
 *
 * @api
 */
final class Socket implements Reader, Writer, Closer
{
    /**
     * Bytes passed to one fwrite() after a partial write, so that a large write is not copied for every syscall.
     */
    private const int WRITE_CHUNK = 1_048_576;

    /**
     * fread() allocates its whole length up front, so one read is bounded whatever the caller asks for:
     * a length from a header must not cost its size in memory before a byte has arrived.
     */
    private const int MAX_READ = 1_048_576;

    /**
     * Reads and writes that do not wait before one yields: enough to drain a burst without a wait for each,
     * few enough that a peer sending without a pause, or reading faster than we write, cannot hold the event loop.
     */
    private const int OPS_PER_TICK = 16;

    /**
     * @internal
     * @param resource $stream a connected socket, owned from now on
     * @param ?non-empty-string $host the host it was connected to, the default peer name of {@see ClientTls}
     */
    public static function create(mixed $stream, bool $unix, ?string $host = null): self
    {
        stream_set_blocking($stream, false);
        // A read goes straight to the socket: no bytes are left in a PHP buffer where the event loop does not see them,
        // and none are read ahead of enableTls().
        stream_set_read_buffer($stream, 0);

        return new self(
            stream: $stream,
            watcher: new StreamWatcher($stream),
            localAddress: address(stream_socket_get_name($stream, false), $unix),
            remoteAddress: address(stream_socket_get_name($stream, true), $unix),
            host: $host,
        );
    }

    public bool $isClosed {
        get => $this->stream === null;
    }

    /**
     * What the handshake agreed on, null until {@see enableTls()} succeeds.
     */
    public private(set) ?TlsInfo $tls = null;

    private readonly Pacer $pacer;

    /**
     * Whether a read is in progress: a second reader is a bug, the two would take bytes from each other.
     */
    private bool $reading = false;

    /**
     * Fibers inside a write that has to wait, holding or waiting for {@see $writeMutex}.
     * While there are none, a write goes to the socket without the mutex.
     */
    private int $writers = 0;

    private ?LocalMutex $writeMutex = null;

    /**
     * @param ?resource $stream
     * @param ?non-empty-string $host
     */
    private function __construct(
        private mixed $stream,
        private readonly StreamWatcher $watcher,
        public readonly Address $localAddress,
        public readonly Address $remoteAddress,
        private readonly ?string $host,
    ) {
        $this->pacer = new Pacer(self::OPS_PER_TICK);
    }

    /**
     * Returns the bytes that have arrived, waiting only if there are none. A read that throws {@see Cancelled}
     * has taken nothing. Reads that find bytes every time still yield to the event loop now and then,
     * so a peer that never pauses does not starve the other tasks.
     *
     * @param positive-int $max
     * @return non-empty-string
     * @throws UnexpectedEof once the peer has closed its side and every byte has been read
     * @throws ReadFailed if the connection is broken (reset by the peer, a TLS error)
     * @throws SocketClosed if this side has closed the socket, during the wait too
     * @throws \LogicException if another fiber is reading
     */
    public function read(int $max): string
    {
        if ($this->reading) {
            throw new \LogicException('Another fiber is reading from the socket.');
        }

        $this->reading = true;

        try {
            $this->pacer->pace();

            while (true) {
                $stream = $this->stream ?? throw new SocketClosed('The socket is closed.');

                // Read first, wait only if there is nothing:
                // TLS keeps decrypted bytes where the event loop does not see them.
                error_clear_last();
                $bytes = @fread($stream, min($max, self::MAX_READ));

                if ($bytes !== '' && $bytes !== false) {
                    return $bytes;
                }

                if ($bytes === false) {
                    // PHP raises no error for a reset of a plain socket, only TLS has its own messages.
                    throw new ReadFailed(lastError('The connection is broken: reset by the peer or timed out.'));
                }

                if (feof($stream)) {
                    throw new UnexpectedEof('The peer has closed the connection.');
                }

                if (!$this->watcher->readable()) {
                    throw new SocketClosed('The socket was closed while reading.');
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
     * If a write fails or is cancelled after a part of its bytes has been sent, the socket is closed:
     * the peer has got half a message, and nothing written afterwards would make sense to it.
     * A write cancelled before sending anything leaves the socket as it was.
     *
     * @throws WriteFailed if the connection is broken
     * @throws SocketClosed if this side has closed the socket, during the wait too
     */
    public function write(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }

        $this->pacer->pace();
        $written = 0;

        if ($this->writers === 0) {
            $stream = $this->stream ?? throw new SocketClosed('The socket is closed.');
            error_clear_last();
            $written = @fwrite($stream, $bytes);

            if ($written === \strlen($bytes)) {
                return;
            }

            if ($written === false || ($written === 0 && error_get_last() !== null)) {
                throw new WriteFailed(lastError('Failed to write to the socket.'));
            }
        }

        $this->finishWrite($bytes, $written);
    }

    /**
     * Shuts down the sending side: the peer reads the end of the stream, while this side can still read
     * what the peer sends. Waits for the writes in progress.
     *
     * @throws SocketClosed
     * @throws \LogicException over TLS: PHP cannot send its close_notify without turning TLS off
     */
    public function closeWrite(): void
    {
        if ($this->tls !== null) {
            throw new \LogicException('A TLS socket cannot be half-closed.');
        }

        $this->exclusively(function () {
            $stream = $this->stream ?? throw new SocketClosed('The socket is closed.');
            stream_socket_shutdown($stream, STREAM_SHUT_WR);
        });
    }

    /**
     * Performs the TLS handshake. Call it right after {@see connect()} or {@see Server::accept()},
     * or once a plaintext protocol has agreed to switch (STARTTLS, the SSLRequest of Postgres).
     * Nothing else may read or write meanwhile.
     *
     * Bound it with `timeout()`: a peer that never answers holds it forever. A handshake that fails or is cancelled
     * closes the socket, since it cannot go on half-way.
     *
     * @throws TlsFailed
     * @throws SocketClosed
     * @throws \LogicException if TLS is already enabled or another fiber reads or writes
     */
    public function enableTls(ClientTls|ServerTls $tls): void
    {
        $stream = $this->stream ?? throw new SocketClosed('The socket is closed.');

        if ($this->tls !== null) {
            throw new \LogicException('TLS is already enabled.');
        }

        if ($this->reading || $this->writers > 0) {
            throw new \LogicException('TLS cannot be enabled while another fiber reads or writes.');
        }

        if ($tls instanceof ClientTls) {
            $options = $tls->contextOptions($this->host);
            $method = $tls->minVersion->clientMethod();
            $captured = true;
        } else {
            $options = $tls->contextOptions();
            $method = $tls->minVersion->serverMethod();
            $captured = $tls->clientCaFile !== null;
        }

        stream_context_set_options($stream, ['ssl' => [...$options, 'peer_certificate' => null]]);

        try {
            while (true) {
                error_clear_last();
                $done = @stream_socket_enable_crypto($stream, enable: true, crypto_method: $method);

                if ($done === true) {
                    break;
                }

                if ($done === false) {
                    throw new TlsFailed('TLS handshake failed: ' . lastError('the peer has closed the connection.'));
                }

                // Checked after the handshake has consumed what has arrived: an alert before the end is reported.
                if (feof($stream)) {
                    throw new TlsFailed('TLS handshake failed: the peer has closed the connection.');
                }

                if (!$this->watcher->readable()) {
                    throw new SocketClosed('The socket was closed during the TLS handshake.');
                }
            }
        } catch (\Throwable $error) {
            $this->close();

            throw $error;
        }

        $this->tls = TlsInfo::fromStream($stream, $captured);
    }

    /**
     * Closes the socket at once. Reads and writes waiting in other fibers throw {@see SocketClosed}.
     * Idempotent.
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

        try {
            $this->exclusively(function () use ($bytes, $length, &$written) {
                while ($written < $length) {
                    $stream = $this->stream ?? throw new SocketClosed('The socket was closed while writing.');
                    error_clear_last();
                    // After a write that sent nothing, the same bytes are passed again: TLS requires it.
                    $sent = @fwrite($stream, $written === 0 ? $bytes : substr($bytes, $written, self::WRITE_CHUNK));

                    // OpenSSL reports a broken pipe as 0 bytes written with a warning: waiting for writability
                    // would spin.
                    if ($sent === false || ($sent === 0 && error_get_last() !== null)) {
                        throw new WriteFailed(lastError('Failed to write to the socket.'));
                    }

                    if ($sent > 0) {
                        $written += $sent;
                    } elseif (!$this->watcher->writable()) {
                        throw new SocketClosed('The socket was closed while writing.');
                    }
                }
            });
        } catch (\Throwable $error) {
            if ($written > 0) {
                $this->close();
            }

            throw $error;
        }
    }

    /**
     * Runs $fn holding the write mutex.
     *
     * @param \Closure(): void $fn
     */
    private function exclusively(\Closure $fn): void
    {
        ++$this->writers;

        try {
            $lock = ($this->writeMutex ??= new LocalMutex())->acquire();

            try {
                $fn();
            } finally {
                $lock->release();
            }
        } finally {
            --$this->writers;
        }
    }
}
