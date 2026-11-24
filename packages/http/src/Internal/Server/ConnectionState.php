<?php

declare(strict_types=1);

namespace Thesis\Http\Internal\Server;

use Thesis\Net\Server;
use Thesis\Net\Socket;

/**
 * @internal
 */
final class ConnectionState
{
    public bool $isStopping {
        get => $this->listener->isClosed;
    }

    /**
     * @var 'http'|'https'
     */
    public string $scheme {
        get => $this->socket->tls === null ? 'http' : 'https';
    }

    private bool $handling = false;

    public function __construct(
        private readonly Server $listener,
        public readonly Socket $socket,
        public readonly ?SocketDeadlines $idle,
        public readonly ?SocketDeadlines $writes,
    ) {}

    public function beginRequest(): bool
    {
        if ($this->isStopping) {
            return false;
        }

        $this->handling = true;

        return true;
    }

    public function endRequest(): void
    {
        $this->handling = false;
    }

    public function stopIfIdle(): void
    {
        if (!$this->handling) {
            $this->socket->close();
        }
    }
}
