<?php

declare(strict_types=1);

namespace Thesis\HttpClient\Internal;

use Thesis\Io\IoException;
use Thesis\Net\Socket;

/**
 * @internal
 */
final class ConnectionPool
{
    /**
     * @var array<string, array<int, Socket>>
     */
    private array $idle = [];

    /**
     * @var array<int, string> socket ids in the order they became idle
     */
    private array $origins = [];

    /**
     * @param non-negative-int $capacity
     */
    public function __construct(
        private readonly int $capacity,
    ) {}

    public function take(string $origin): ?Socket
    {
        while (isset($this->idle[$origin])) {
            $id = array_key_last($this->idle[$origin]);
            \assert($id !== null);
            $socket = $this->remove($origin, $id);

            try {
                // No request is outstanding. Any bytes waiting here are unsolicited.
                if ($socket->tryRead(1) === null) {
                    return $socket;
                }
            } catch (IoException) {
                // The peer closed the connection while it was idle.
            }

            $socket->close();
        }

        return null;
    }

    public function release(string $origin, Socket $socket): void
    {
        if ($this->capacity === 0) {
            $socket->close();

            return;
        }

        if (\count($this->origins) === $this->capacity) {
            $oldest = array_key_first($this->origins);
            $this->remove($this->origins[$oldest], $oldest)->close();
        }

        $id = spl_object_id($socket);
        $this->idle[$origin][$id] = $socket;
        $this->origins[$id] = $origin;
    }

    private function remove(string $origin, int $id): Socket
    {
        \assert(isset($this->idle[$origin][$id]));
        $socket = $this->idle[$origin][$id];
        unset($this->idle[$origin][$id], $this->origins[$id]);
        if (($this->idle[$origin] ?? []) === []) {
            unset($this->idle[$origin]);
        }

        return $socket;
    }

    public function __destruct()
    {
        foreach ($this->idle as $sockets) {
            foreach ($sockets as $socket) {
                $socket->close();
            }
        }
    }
}
