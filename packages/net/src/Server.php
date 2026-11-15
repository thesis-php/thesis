<?php

declare(strict_types=1);

namespace Thesis\Net;

use Thesis\Async\Cancelled;
use Thesis\Async\Pacer;
use Thesis\Async\StreamWatcher;
use function Thesis\Net\Internal\address;
use function Thesis\Time\ns;

/**
 * A listening socket, created by {@see listen()} or {@see listenUnix()}.
 *
 * @api
 */
final class Server
{
    /**
     * Pause after an accept that fails although a connection is pending: the process may be out of file descriptors,
     * and retrying at once would spin. Doubles up to {@see MAX_BACKOFF}. In nanoseconds.
     */
    private const int MIN_BACKOFF = 5_000_000;
    private const int MAX_BACKOFF = 1_000_000_000;

    /**
     * Accepts that find a connection at once before one yields, so that a flood of connections
     * does not starve the ones already accepted.
     */
    private const int ACCEPTS_PER_TICK = 16;

    /**
     * @internal
     * @param resource $stream a listening socket, owned from now on
     * @param ?non-empty-string $unixPath the path of a Unix socket, removed by {@see close()}
     */
    public static function create(mixed $stream, ?string $unixPath = null): self
    {
        stream_set_blocking($stream, false);

        return new self(
            stream: $stream,
            watcher: new StreamWatcher($stream),
            address: $unixPath === null
                ? address(stream_socket_get_name($stream, false), unix: false)
                : new UnixAddress(path: $unixPath),
            unixPath: $unixPath,
        );
    }

    public bool $isClosed {
        get => $this->stream === null;
    }

    private readonly Pacer $pacer;

    /**
     * @param ?resource $stream
     * @param ?non-empty-string $unixPath
     */
    private function __construct(
        private mixed $stream,
        private readonly StreamWatcher $watcher,
        /**
         * Where the server listens, with the port the OS has chosen if it was 0.
         */
        public readonly Address $address,
        private readonly ?string $unixPath,
    ) {
        $this->pacer = new Pacer(self::ACCEPTS_PER_TICK);
    }

    /**
     * Waits for the next connection. Returns null once the server is closed, a wait in progress included,
     * so that an accept loop ends by itself:
     *
     *     while (null !== $socket = $server->accept()) {
     *         $group->spawn(static fn() => handle($socket));
     *     }
     *
     * No TLS handshake happens here, so that a slow client does not hold up the others:
     * call {@see Socket::enableTls()} in the task of the connection.
     *
     * @throws Cancelled
     */
    public function accept(): ?Socket
    {
        $this->pacer->pace();
        $failures = 0;
        $woken = false;

        while ($this->stream !== null) {
            $client = @stream_socket_accept($this->stream, 0);

            if ($client !== false) {
                return Socket::create($client, unix: $this->unixPath !== null);
            }

            if ($woken) {
                // Readable, but nothing to accept: another fiber has taken it, or accept() itself fails.
                $woken = false;
                // The shift is capped: past MAX_BACKOFF anyway, and an int must not overflow.
                $backoff = min(self::MIN_BACKOFF << min($failures, 30), self::MAX_BACKOFF);
                ++$failures;

                if (!$this->watcher->delay(ns($backoff))) {
                    return null;
                }
            } elseif ($this->watcher->readable()) {
                $woken = true;
            } else {
                return null;
            }
        }

        return null;
    }

    /**
     * Stops listening: waiting {@see accept()} calls return null, accepted sockets stay open.
     * The file of a Unix socket is removed. Idempotent.
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

        if ($this->unixPath !== null) {
            @unlink($this->unixPath);
        }
    }
}
