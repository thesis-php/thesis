<?php

declare(strict_types=1);

namespace Thesis\Amqp;

use Thesis\Net\InetAddress;
use Thesis\Net\Server;
use Time\Duration;
use function Thesis\Net\listen;

/**
 * A broker on a free local port that does what a test tells it: each accepted connection is a {@see FakePeer}.
 */
final readonly class FakeServer
{
    private function __construct(
        private Server $server,
    ) {}

    public static function listen(): self
    {
        return new self(listen('127.0.0.1', 0));
    }

    public function config(bool $reconnect = false, ?Duration $heartbeat = null): Config
    {
        $address = $this->server->address;
        \assert($address instanceof InetAddress);

        return new Config(
            hosts: ["127.0.0.1:{$address->port}"],
            heartbeat: $heartbeat ?? \Thesis\Time\sec(0),
            reconnect: $reconnect,
        );
    }

    public function accept(): FakePeer
    {
        $socket = $this->server->accept();
        \assert($socket !== null);

        return new FakePeer($socket);
    }

    public function close(): void
    {
        $this->server->close();
    }
}
