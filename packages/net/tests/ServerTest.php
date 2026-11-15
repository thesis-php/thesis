<?php

declare(strict_types=1);

namespace Thesis\Net;

use Revolt\EventLoop;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Group;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\timeoutOr;

#[Test]
final class ServerTest
{
    public function listensOnAFreePort(): void
    {
        $server = listen('127.0.0.1', 0);

        Assert::instanceOf($server->address, InetAddress::class);
        Assert::true($server->address->port > 0);
        $server->close();
    }

    public function closeEndsAWaitingAccept(): void
    {
        $server = listen('127.0.0.1', 0);

        $accepted = group(static function (Group $group) use ($server) {
            $accepted = $group->spawn($server->accept(...));
            delay(0.01);
            $server->close();

            return $accepted->await();
        });

        Assert::null($accepted);
        Assert::true($server->isClosed);
        Assert::null($server->accept());
    }

    public function acceptIsCancellable(): void
    {
        $server = listen('127.0.0.1', 0);

        Assert::same(timeoutOr(0.01, $server->accept(...), default: 'cancelled'), 'cancelled');
        $server->close();
    }

    public function acceptsConnectionsUntilClosed(): void
    {
        $server = listen('127.0.0.1', 0);
        \assert($server->address instanceof InetAddress);
        $address = $server->address;

        $greetings = group(static function (Group $group) use ($server, $address) {
            $group->spawn(static function () use ($server) {
                while (null !== $socket = $server->accept()) {
                    $socket->write('hello ' . $socket->read(10));
                    $socket->close();
                }
            });

            $greetings = [];

            foreach (['a', 'b', 'c'] as $name) {
                $client = connect($address->ip, $address->port);
                $client->write($name);
                $greetings[] = readExactly($client, 7);
            }

            $server->close();

            return $greetings;
        });

        Assert::same($greetings, ['hello a', 'hello b', 'hello c']);
    }

    public function acceptsThatNeverWaitStillLetOtherCallbacksRun(): void
    {
        $server = listen('127.0.0.1', 0);
        \assert($server->address instanceof InetAddress);
        $clients = [];

        for ($i = 0; $i < 100; ++$i) {
            $clients[] = connect('127.0.0.1', $server->address->port);
        }

        $progress = new \stdClass();
        $progress->accepted = [];
        $progress->acceptedWhenTicked = null;
        EventLoop::defer(static function () use ($progress) {
            $progress->acceptedWhenTicked = \count($progress->accepted);
        });

        while (\count($progress->accepted) < 100) {
            $progress->accepted[] = $server->accept();
        }

        Assert::true(\is_int($progress->acceptedWhenTicked) && $progress->acceptedWhenTicked < 100);
        $server->close();
    }

    public function failsToListenOnABusyPort(): void
    {
        $server = listen('127.0.0.1', 0);
        \assert($server->address instanceof InetAddress);
        Expect::exception(ListenFailed::class)->withMessageContaining('Address in use');

        try {
            listen('127.0.0.1', $server->address->port);
        } finally {
            $server->close();
        }
    }

    public function sharesAPortWithReusePort(): void
    {
        $first = listen('127.0.0.1', 0, reusePort: true);
        \assert($first->address instanceof InetAddress);

        $second = listen('127.0.0.1', $first->address->port, reusePort: true);

        Assert::same((string) $second->address, (string) $first->address);
        $first->close();
        $second->close();
    }

    public function rejectsAHostName(): void
    {
        Expect::exception(\ValueError::class)->withMessage('"localhost" is not an IP address.');

        listen('localhost', 0);
    }

    public function listensOnAUnixSocket(): void
    {
        $path = sys_get_temp_dir() . '/thesis-net-' . bin2hex(random_bytes(4)) . '.sock';
        $server = listenUnix($path);

        $echo = group(static function (Group $group) use ($server, $path) {
            $group->spawn(static function () use ($server) {
                $socket = $server->accept();
                \assert($socket !== null);
                $socket->write($socket->read(10));
            });
            $client = connectUnix($path);
            $client->write('ping');

            return readExactly($client, 4);
        });

        Assert::same($echo, 'ping');
        Assert::same((string) $server->address, 'unix:' . $path);
        $server->close();
        Assert::false(file_exists($path));
    }

    public function failsToConnectToAMissingUnixSocket(): void
    {
        Expect::exception(ConnectFailed::class)->withMessageContaining('No such file or directory');

        connectUnix('/nonexistent/thesis.sock');
    }
}
