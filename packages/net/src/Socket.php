<?php

declare(strict_types=1);

namespace Thesis\Net;

use Thesis\Async\Channel;
use Thesis\Async\Pacer;
use Thesis\Async\ReadObserver;
use Thesis\Async\SendResult;
use Thesis\Async\StreamWatcher;
use Thesis\Io\Closer;
use Thesis\Io\ConcurrentRead;
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
 * Several fibers may write while a read is pending. Writes never interleave.
 *
 * @api
 */
final class Socket implements Reader, Writer, Closer
{
    /**
     * Bytes passed to one fwrite() after a partial write, so that a large write is not copied for every syscall.
     */
    private const int WRITE_CHUNK = 1024 * 1024;

    /**
     * fread() allocates its whole length up front, so one read is bounded whatever the caller asks for:
     * a length from a header must not cost its size in memory before a byte has arrived.
     */
    private const int MAX_READ = 1024 * 1024;

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

    /**
     * @var ?resource
     */
    private mixed $stream;

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
     * Whether {@see enableTls()} is in progress: a second handshake would interleave its records with the first.
     */
    private bool $handshaking = false;

    /**
     * @param resource $stream
     * @param ?non-empty-string $host
     */
    private function __construct(
        mixed $stream,
        private readonly StreamWatcher $watcher,
        public readonly Address $localAddress,
        public readonly Address $remoteAddress,
        private readonly ?string $host,
    ) {
        $this->stream = $stream;
        $this->pacer = new Pacer(self::OPS_PER_TICK);
    }

    /**
     * Reads that find bytes every time still yield to the event loop now and then,
     * so a peer that never pauses does not starve the other tasks.
     */
    public function read(int $max): ?string
    {
        if ($this->reading) {
            throw new ConcurrentRead('A read is already in progress on the socket.');
        }

        $this->reading = true;

        try {
            $this->pacer->pace();

            while (true) {
                $bytes = $this->readAvailable($max);
                if ($bytes !== null) {
                    return $bytes === '' ? null : $bytes;
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
     * Waits for a possible peer shutdown without consuming bytes. A local close returns false.
     * TLS and backends without a distinct shutdown event wake on readability. Confirm with tryRead().
     */
    public function awaitReadHangUp(): bool
    {
        return $this->tls === null ? $this->watcher->readHangUp() : $this->watcher->readable();
    }

    /**
     * Observe possible peer shutdown without consuming any bytes. TLS and portable backends wake on readability.
     * Return true to await another event, or false to pause. Closing the socket removes the observer.
     *
     * @param \Closure(ReadObserver): bool $probe a callback that must not suspend
     * @throws SocketClosed if the socket is closed
     */
    public function observeReadHangUp(\Closure $probe): ReadObserver
    {
        if ($this->isClosed) {
            throw new SocketClosed('Cannot observe a closed socket.');
        }

        return $this->watcher->observeReadable($probe, hangUpOnly: $this->tls === null);
    }

    /**
     * Reads once without waiting. Returns null when the peer has no bytes ready.
     *
     * @param positive-int $max
     * @return ?non-empty-string
     * @throws ConcurrentRead if another read is in progress
     * @throws SocketClosed if the socket is closed
     * @throws UnexpectedEof if the peer has finished writing
     * @throws ReadFailed on a transport error
     */
    public function tryRead(int $max): ?string
    {
        if ($this->reading) {
            throw new ConcurrentRead('A read is already in progress on the socket.');
        }

        $bytes = $this->readAvailable($max);
        if ($bytes === '') {
            throw new UnexpectedEof('The peer has closed the connection.');
        }

        return $bytes;
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
            throw new SocketClosed('The socket is closed.');
        }

        $this->beginWrite();
        $chunk = $bytes;
        $length = \strlen($bytes);
        $written = 0;

        try {
            $this->pacer->pace();

            while (true) {
                $stream = $this->stream ?? throw new SocketClosed('The socket was closed while writing.');
                $sent = self::send($stream, $chunk);
                $written += $sent;

                if ($written === $length) {
                    return;
                }

                if ($sent === 0 && !$this->watcher->writable()) {
                    throw new SocketClosed('The socket was closed while writing.');
                }

                $chunk = substr($bytes, $written, self::WRITE_CHUNK);
            }
        } catch (\Throwable $error) {
            if ($written > 0) {
                $this->close();
            }

            throw $error;
        } finally {
            $this->endWrite();
        }
    }

    /**
     * Tries one plaintext write. Returns the bytes sent, or zero when a writer owns the socket, the pacer
     * would yield, or the peer has no room. During or after a TLS handshake it returns zero: only write()
     * can keep ownership of the pending OpenSSL record until the retry.
     *
     * @return non-negative-int
     * @throws SocketClosed
     * @throws WriteFailed
     */
    public function tryWrite(string $bytes): int
    {
        if ($bytes === '') {
            return 0;
        }

        $stream = $this->stream ?? throw new SocketClosed('The socket is closed.');
        if ($this->writing || $this->tls !== null || $this->handshaking || !$this->pacer->tryPace()) {
            return 0;
        }

        return self::send($stream, $bytes);
    }

    /**
     * @param resource $stream
     * @return non-negative-int
     */
    private static function send(mixed $stream, string $bytes): int
    {
        error_clear_last();
        $sent = @fwrite($stream, $bytes);
        if ($sent === false || ($sent === 0 && error_get_last() !== null)) {
            throw new WriteFailed(lastError('Failed to write to the socket.'));
        }

        return $sent;
    }

    /**
     * Waits for pending writes and shuts down writes on this socket. The peer reads to the end of the stream,
     * and this socket can still read what the peer sends.
     * PHP cannot send a TLS close_notify without turning off TLS.
     *
     * @throws SocketClosed
     * @throws \LogicException if TLS is enabled on the socket
     */
    public function closeWrite(): void
    {
        if ($this->tls !== null) {
            throw new \LogicException('A TLS socket cannot be half-closed.');
        }

        $this->beginWrite();

        try {
            $stream = $this->stream ?? throw new SocketClosed('The socket is closed.');
            stream_socket_shutdown($stream, STREAM_SHUT_WR);
        } finally {
            $this->endWrite();
        }
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
     * @throws \LogicException if TLS is already enabled or being enabled, or another fiber reads or writes
     */
    public function enableTls(ClientTls|ServerTls $tls): void
    {
        $stream = $this->stream ?? throw new SocketClosed('The socket is closed.');

        if ($this->tls !== null) {
            throw new \LogicException('TLS is already enabled.');
        }

        if ($this->handshaking) {
            throw new \LogicException('TLS is already being enabled.');
        }

        if ($this->reading || $this->writing) {
            throw new \LogicException('TLS cannot be enabled while another fiber reads or writes.');
        }

        if ($tls instanceof ClientTls) {
            $options = $tls->contextOptions($this->host);
            $method = $tls->minVersion->clientMethod();
            $captured = true;
        } else {
            $options = $tls->contextOptions;
            $method = $tls->minVersion->serverMethod();
            $captured = $tls->clientCaFile !== null;
        }

        stream_context_set_options($stream, ['ssl' => [...$options, 'peer_certificate' => null]]);
        $this->handshaking = true;

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

                $this->watcher->readable();
                $stream = $this->stream ?? throw new SocketClosed('The socket was closed during the TLS handshake.');
            }
        } catch (\Throwable $error) {
            $this->close();

            throw $error;
        } finally {
            $this->handshaking = false;
        }

        $this->tls = TlsInfo::fromStream($stream, $captured);
    }

    /**
     * Closes the socket immediately.
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
     * @param positive-int $max
     * @return ?string null while waiting for bytes, an empty string at EOF
     */
    private function readAvailable(int $max): ?string
    {
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

        return feof($stream) ? '' : null;
    }

    private function beginWrite(): void
    {
        if ($this->writing) {
            ($this->waitingWriters ??= new Channel(0))->receive();
        }

        $this->writing = true;
    }

    private function endWrite(): void
    {
        $this->writing = $this->waitingWriters?->trySend(true) === SendResult::Sent;
    }
}
