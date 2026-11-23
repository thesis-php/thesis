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
     * One idle socket per origin, in the order they became idle.
     *
     * @var array<string, Socket>
     */
    private array $idle = [];

    public function __destruct()
    {
        foreach ($this->idle as $socket) {
            $socket->close();
        }
    }

    public function take(string $origin): ?Socket
    {
        $socket = $this->idle[$origin] ?? null;
        if ($socket === null) {
            return null;
        }

        unset($this->idle[$origin]);

        try {
            // No request is outstanding. Any bytes waiting here are unsolicited.
            if ($socket->tryRead(1) === null) {
                return $socket;
            }
        } catch (IoException) {
            // The peer closed the connection while it was idle.
        }

        $socket->close();

        return null;
    }

    public function release(string $origin, Socket $socket): void
    {
        if (isset($this->idle[$origin])) {
            $this->idle[$origin]->close();
            unset($this->idle[$origin]);
        } elseif (\count($this->idle) === 32) {
            array_shift($this->idle)->close();
        }

        $this->idle[$origin] = $socket;
    }
}
