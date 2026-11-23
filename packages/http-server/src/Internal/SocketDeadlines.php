<?php

declare(strict_types=1);

namespace Thesis\HttpServer\Internal;

use Thesis\Async\Group;
use Thesis\Async\Instant;
use Thesis\Io\WriteFailed;
use Thesis\Net\Socket;
use Thesis\Net\SocketClosed;
use Time\Duration;
use function Thesis\Async\checkpoint;
use function Thesis\Async\delay;

/**
 * Socket operations with the same timeout expire in the order they began.
 *
 * @internal
 */
final class SocketDeadlines
{
    /**
     * @var array<int, array{Instant, Socket}> keyed by operation, as writes on the same socket may overlap
     */
    private array $waiting = [];

    private bool $running = false;

    public function __construct(
        private readonly Group $group,
        private readonly Duration $timeout,
    ) {}

    /**
     * @param positive-int $max
     * @return ?non-empty-string null when the socket's idle wait expires
     */
    public function read(Socket $socket, int $max): ?string
    {
        $key = $this->start($socket);

        try {
            if (\count($this->waiting) > 1) {
                // Serve other ready clients while this one receives the response and sends its next request.
                checkpoint();
                $bytes = $socket->tryRead($max);
                if ($bytes !== null) {
                    return $bytes;
                }
            }

            return $socket->read($max);
        } catch (SocketClosed $error) {
            // expire() removes the socket before closing it. A close by another owner still propagates.
            if (\array_key_exists($key, $this->waiting)) {
                throw $error;
            }

            return null;
        } finally {
            unset($this->waiting[$key]);
        }
    }

    public function write(Socket $socket, string $bytes): void
    {
        $start = Instant::now();
        $sent = $socket->tryWrite($bytes);
        if ($sent === \strlen($bytes)) {
            return;
        }

        $key = null;

        try {
            $key = $this->start($socket, $start);
            $socket->write(substr($bytes, $sent));
        } catch (SocketClosed $error) {
            if (\array_key_exists($key, $this->waiting)) {
                throw $error;
            }

            throw new WriteFailed('The response write did not complete in time.', previous: $error);
        } catch (\Throwable $error) {
            if ($sent > 0) {
                $socket->close();
            }

            throw $error;
        } finally {
            if ($key !== null) {
                unset($this->waiting[$key]);
            }
        }
    }

    private function start(Socket $socket, ?Instant $start = null): int
    {
        if (!$this->running) {
            $this->group->daemon($this->expire(...));
            $this->running = true;
        }

        $this->waiting[] = [$start ?? Instant::now(), $socket];

        return array_key_last($this->waiting);
    }

    private function expire(): void
    {
        try {
            while (($first = array_first($this->waiting)) !== null) {
                $elapsed = Instant::now()->since($first[0]);
                if ($elapsed < $this->timeout) {
                    // The oldest operation may finish while we sleep. Look up the oldest remaining operation on wake.
                    unset($first);
                    delay($this->timeout->sub($elapsed));

                    continue;
                }

                /** @var int $key the queue still holds $first */
                $key = array_key_first($this->waiting);
                unset($this->waiting[$key]);
                $first[1]->close();
            }
        } finally {
            $this->running = false;
        }
    }
}
