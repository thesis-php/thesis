<?php

declare(strict_types=1);

namespace Thesis\HttpServer\Internal;

use Thesis\Async\Group;
use Thesis\Async\Instant;
use Thesis\Net\Socket;
use Thesis\Net\SocketClosed;
use Time\Duration;
use function Thesis\Async\checkpoint;
use function Thesis\Async\delay;

/**
 * All sockets of the listener have the same idle timeout, so they expire in the order their waits began.
 *
 * @internal
 */
final class IdleConnections
{
    /**
     * @var array<int, array{Instant, Socket}> keyed by the socket's object id
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
        $key = spl_object_id($socket);
        $this->waiting[$key] = [Instant::now(), $socket];

        try {
            if (!$this->running) {
                $this->running = true;
                $this->group->daemon($this->expire(...));
            }

            if (\count($this->waiting) > 1) {
                // Serve other ready clients while this one receives the response and sends its next request.
                checkpoint();
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

    private function expire(): void
    {
        try {
            while (($first = array_first($this->waiting)) !== null) {
                $elapsed = Instant::now()->since($first[0]);
                if ($elapsed < $this->timeout) {
                    // The oldest read may finish while we sleep. Look up the oldest remaining read on wake.
                    unset($first);
                    delay($this->timeout->sub($elapsed));

                    continue;
                }

                unset($this->waiting[spl_object_id($first[1])]);
                $first[1]->close();
            }
        } finally {
            $this->running = false;
        }
    }
}
