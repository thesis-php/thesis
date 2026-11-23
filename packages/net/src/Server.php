<?php

declare(strict_types=1);

namespace Thesis\Net;

use Thesis\Async\Backoff;
use Thesis\Async\Deferred;
use Thesis\Async\Pacer;
use Thesis\Async\StreamWatcher;
use function Thesis\Net\Internal\address;
use function Thesis\Time\ms;
use function Thesis\Time\sec;

/**
 * {@see listen()} and {@see listenUnix()} create a listening socket.
 *
 * @api
 */
final class Server
{
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
        // A blocking listener would hang in accept() if another process took the connection between the poll
        // of stream_socket_accept() and its accept: no test can time that.
        // @infection-ignore FalseValue, FunctionCallRemoval
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

    /**
     * @var ?resource
     */
    private mixed $stream;

    private readonly Pacer $pacer;

    /**
     * @var ?Deferred<null>
     */
    private ?Deferred $closed = null;

    /**
     * @param resource $stream
     * @param Address $address where the server listens, with the port the OS chose if the requested port was 0
     * @param ?non-empty-string $unixPath
     */
    private function __construct(
        mixed $stream,
        private readonly StreamWatcher $watcher,
        public readonly Address $address,
        private readonly ?string $unixPath,
    ) {
        $this->stream = $stream;
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
     */
    public function accept(): ?Socket
    {
        $this->pacer->pace();
        $backoff = null;
        // As true, only the first wait would begin with the first backoff, 5 ms: too short for a test to tell.
        // @infection-ignore FalseValue
        $woken = false;

        while ($this->stream !== null) {
            $client = @stream_socket_accept($this->stream, 0);

            if ($client !== false) {
                return Socket::create($client, unix: $this->unixPath !== null);
            }

            if ($woken) {
                // Readable, but nothing to accept: another fiber has taken it, or accept() itself fails.
                // The process may be out of file descriptors, and retrying at once would spin.
                $woken = false;
                $backoff ??= new Backoff(initial: ms(5), max: sec(1), jitter: false);

                if (!$this->watcher->delay($backoff->next())) {
                    return null;
                }
            } else {
                // false only after close(), which ends the loop
                $woken = $this->watcher->readable();
            }
        }

        return null;
    }

    /**
     * Waits for {@see close()} without accepting a connection. Returns immediately if already closed.
     */
    public function awaitClose(): void
    {
        if (!$this->isClosed) {
            ($this->closed ??= new Deferred())->await();
        }
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

        $this->closed?->complete();
        $this->closed = null;
    }
}
