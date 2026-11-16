<?php

declare(strict_types=1);

namespace Thesis\Net;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Group;
use Thesis\Async\Instant;
use Thesis\Async\TimedOut;
use function Thesis\Async\group;
use function Thesis\Async\timeout;
use function Thesis\Time\ms;
use function Thesis\Time\sec;

#[Test]
final class ConnectTest
{
    public function connectsToAnIpAddress(): void
    {
        [$client] = tcpPair();

        Assert::false($client->isClosed);
    }

    public function takesIpv6WithBrackets(): void
    {
        $server = listen('[::1]', 0);
        \assert($server->address instanceof InetAddress);

        $socket = connect('[::1]', $server->address->port);

        Assert::same((string) $socket->remoteAddress, (string) $server->address);
        $server->close();
    }

    public function failsIfRefused(): void
    {
        $port = self::freePort('127.0.0.1');
        Expect
            ::exception(ConnectFailed::class)
            ->withMessage("Failed to connect to 127.0.0.1:{$port}: 127.0.0.1:{$port}: Connection refused")
        ;

        connect('127.0.0.1', $port);
    }

    public function fallsBackToTheNextAddress(): void
    {
        $server = listen('127.0.0.1', self::freePort('::1'));
        \assert($server->address instanceof InetAddress);

        $socket = connect('dual.test', $server->address->port, resolver: new StaticResolver(['::1', '127.0.0.1']));

        Assert::same((string) $socket->remoteAddress, (string) $server->address);
        $server->close();
    }

    public function listsEveryFailedAttempt(): void
    {
        $port = self::freePort('127.0.0.1');
        Expect::exception(ConnectFailed::class)->withMessage(
            "Failed to connect to dual.test:{$port}: [::1]:{$port}: Connection refused; "
            . "127.0.0.1:{$port}: Connection refused",
        );

        connect('dual.test', $port, resolver: new StaticResolver(['::1', '127.0.0.1']));
    }

    public function startsTheNextAttemptWhileThePreviousHangs(): void
    {
        // A listener whose accept queue is full drops SYNs, so a connection to it hangs.
        $full = listen('127.0.0.1', 0, backlog: 1);
        \assert($full->address instanceof InetAddress);
        $port = $full->address->port;
        $fillers = [];

        for ($i = 0; $i < 3; ++$i) {
            $fillers[] = stream_socket_client(
                address: "tcp://127.0.0.1:{$port}",
                error_code: $errno,
                error_message: $error,
                timeout: null,
                flags: STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            );
        }

        $server = listen('::1', $port);
        $start = Instant::now();

        $socket = timeout(sec(2), static fn() => connect(
            host: 'dual.test',
            port: $port,
            resolver: new StaticResolver(['127.0.0.1', '::1']),
        ));

        Assert::same((string) $socket->remoteAddress, "[::1]:{$port}");
        $elapsed = Instant::now()->since($start);
        // libuv counts a timer from the time cached at the start of the loop iteration, so Uv fires it early
        // by as much as the iteration has run: the bound only tells the delay from an immediate second attempt.
        Assert::true($elapsed >= ms(200));
        Assert::true($elapsed < sec(1));
        $full->close();
        $server->close();
        unset($fillers);
    }

    public function wrapsAResolverFailure(): void
    {
        Expect
            ::exception(ConnectFailed::class)
            ->withMessage('Failed to connect to missing.test:80: Host "missing.test" not found.')
            ->withPrevious(DnsFailed::class)
        ;

        connect('missing.test', 80, resolver: new StaticResolver([]));
    }

    public function isCancellable(): void
    {
        $full = listen('127.0.0.1', 0, backlog: 1);
        \assert($full->address instanceof InetAddress);
        $port = $full->address->port;
        $fillers = [];

        for ($i = 0; $i < 3; ++$i) {
            $fillers[] = stream_socket_client(
                address: "tcp://127.0.0.1:{$port}",
                error_code: $errno,
                error_message: $error,
                timeout: null,
                flags: STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            );
        }

        Expect::exception(TimedOut::class);

        try {
            timeout(ms(50), static fn() => connect('127.0.0.1', $port));
        } finally {
            $full->close();
            unset($fillers);
        }
    }

    public function resolvesWithTheSystemResolver(): void
    {
        $server = listen('127.0.0.1', 0);
        \assert($server->address instanceof InetAddress);
        $address = $server->address;

        $accepted = group(static function (Group $group) use ($server, $address) {
            $accepted = $group->spawn($server->accept(...));
            connect('localhost', $address->port);

            return $accepted->await();
        });

        Assert::notNull($accepted);
        $server->close();
    }

    /**
     * @param non-empty-string $host
     * @return int<1, 65535> a port nothing listens on, at least for now
     */
    private static function freePort(string $host): int
    {
        $server = listen($host, 0);
        \assert($server->address instanceof InetAddress);
        $port = $server->address->port;
        \assert($port > 0);
        $server->close();

        return $port;
    }
}
