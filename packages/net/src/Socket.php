<?php

declare(strict_types=1);

namespace Thesis\Net;

use Thesis\Async\LocalMutex;
use Thesis\Async\Pacer;
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

    /**
     * Fibers inside a write that has to wait, holding or waiting for {@see $writeMutex}.
     * While there are none, a write goes to the socket without the mutex.
     */
    private int $writers = 0;

    private ?LocalMutex $writeMutex = null;

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
    public function read(int $max): string
    {
        if ($this->reading) {
            throw new ConcurrentRead('A read is already in progress on the socket.');
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
     * Returns once every byte is handed to the OS.
     * Concurrent writes go one after another, in the order they came.
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
            // A failed fwrite() of a socket reports its own warning, which replaces the last error.
            // @infection-ignore FunctionCallRemoval
            error_clear_last();
            $written = @fwrite($stream, $bytes);

            if ($written === \strlen($bytes)) {
                // Without it the write takes the mutex and writes nothing more.
                // @infection-ignore ReturnRemoval
                return;
            }

            // 0 bytes with an error (a broken pipe of TLS) fails in finishWrite(), which passes the same bytes again.
            if ($written === false) {
                throw new WriteFailed(lastError('Failed to write to the socket.'));
            }
        }

        $this->finishWrite($bytes, $written);
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

        if ($this->reading || $this->writers > 0) {
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
     * When no other writes are pending, the fast path can write some bytes and take the free mutex
     * before another write starts.
     *
     * @param int $written bytes already written by the fast path, or 0 if the write skipped the fast path
     */
    private function finishWrite(string $bytes, int $written): void
    {
        $length = \strlen($bytes);

        try {
            $this->exclusively(function () use ($bytes, $length, &$written) {
                while ($written < $length) {
                    $stream = $this->stream ?? throw new SocketClosed('The socket was closed while writing.');
                    // Only a write that sends nothing once the socket is writable could read an error of another
                    // call, and no test can make the OS do that.
                    // @infection-ignore FunctionCallRemoval
                    error_clear_last();
                    // After a write that sent nothing, TLS sends the record it has made of the first bytes,
                    // so a retry begins with the same bytes. PHP lets the buffer move and be shorter
                    // (SSL_MODE_ACCEPT_MOVING_WRITE_BUFFER, SSL_MODE_ENABLE_PARTIAL_WRITE): a chunk holds the record.
                    $sent = @fwrite($stream, substr($bytes, $written, self::WRITE_CHUNK));

                    // OpenSSL reports a broken pipe as 0 bytes written with a warning: waiting for writability
                    // would spin.
                    if ($sent === false || ($sent === 0 && error_get_last() !== null)) {
                        throw new WriteFailed(lastError('Failed to write to the socket.'));
                    }

                    if ($sent > 0) {
                        $written += $sent;
                    } elseif (!$this->watcher->writable()) {
                        // The watcher ends a wait with false only after close(), so the loop would throw the same
                        // at its start: the throw says it where the wait ends, as in read().
                        // @infection-ignore Throw_
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
     * @param callable(): void $fn
     */
    private function exclusively(callable $fn): void
    {
        ++$this->writers;

        try {
            // released by its destructor once the method returns or throws
            $lock = ($this->writeMutex ??= new LocalMutex())->acquire();
            $fn();
        } finally {
            --$this->writers;
        }
    }
}
