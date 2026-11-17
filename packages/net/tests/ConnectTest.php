<?php

declare(strict_types=1);

namespace Thesis\Net;

use Testo\Assert;
use Testo\Data\DataSet;
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
        // Nothing listens on [::1] at the port the OS has picked for 127.0.0.1. A port found free on [::1]
        // and then taken on 127.0.0.1 may be held there by a TCP connection of another test: "Address in use".
        $server = listen('127.0.0.1', 0);
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
        [$full, $fillers] = self::hangingListener();
        \assert($full->address instanceof InetAddress);
        $port = $full->address->port;

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

    public function triesTheNextAddressAtOnceIfOneFails(): void
    {
        [$full, $fillers] = self::hangingListener();
        \assert($full->address instanceof InetAddress);
        $port = $full->address->port;
        // nothing listens on [::1] at this port
        $server = listen('127.0.0.2', $port);
        $start = Instant::now();

        $socket = timeout(sec(2), static fn() => connect(
            host: 'triple.test',
            port: $port,
            resolver: new StaticResolver(['127.0.0.1', '::1', '127.0.0.2']),
        ));

        Assert::same((string) $socket->remoteAddress, "127.0.0.2:{$port}");
        // one delay for the address that hangs, none for the one that refuses
        Assert::true(Instant::now()->since($start) < ms(400));
        $full->close();
        $server->close();
        unset($fillers);
    }

    #[DataSet([true])]
    #[DataSet([false])]
    public function turnsNagleOffUnlessAsked(bool $noDelay): void
    {
        $server = listen('127.0.0.1', 0);
        \assert($server->address instanceof InetAddress);

        $socket = connect('127.0.0.1', $server->address->port, noDelay: $noDelay);

        Assert::same(noDelay($socket), $noDelay);
        $server->close();
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
        [$full, $fillers] = self::hangingListener();
        \assert($full->address instanceof InetAddress);
        $port = $full->address->port;

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
     * A listener whose accept queue is full drops SYNs, so a connection to it hangs.
     *
     * @return array{Server, list<mixed>} the listener and the connections that fill its queue, to keep
     */
    private static function hangingListener(): array
    {
        $full = listen('127.0.0.1', 0, backlog: 1);
        \assert($full->address instanceof InetAddress);
        $fillers = [];

        for ($i = 0; $i < 3; ++$i) {
            $fillers[] = stream_socket_client(
                address: "tcp://127.0.0.1:{$full->address->port}",
                error_code: $errno,
                error_message: $error,
                timeout: null,
                flags: STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            );
        }

        return [$full, $fillers];
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
