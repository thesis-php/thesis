<?php

declare(strict_types=1);

namespace Thesis\HttpServer\Internal;

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
        public readonly ?IdleConnections $idle,
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
