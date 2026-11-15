<?php

declare(strict_types=1);

namespace Thesis\Net;

use Revolt\EventLoop;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Group;
use Thesis\Io\ReadFailed;
use Thesis\Io\UnexpectedEof;
use Thesis\Io\WriteFailed;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\timeoutOr;

#[Test]
final class SocketTest
{
    public function readsWhatThePeerWrites(): void
    {
        [$left, $right] = socketPair();

        $left->write('hello');

        Assert::same($right->read(100), 'hello');
    }

    public function readsMoreThanThePhpChunkAtOnce(): void
    {
        [$client, $server] = tcpPair();
        $client->write(str_repeat('x', 100_000));
        delay(0.01);

        Assert::true(\strlen($server->read(65_536)) > 8_192);
    }

    public function transfersLargePayloadsBothWaysAtOnce(): void
    {
        [$client, $server] = tcpPair();
        $up = random_bytes(8_000_000);
        $down = random_bytes(8_000_000);

        [$receivedUp, $receivedDown] = exchange($client, $server, $up, $down);

        Assert::true($receivedUp === $up);
        Assert::true($receivedDown === $down);
    }

    public function writesOfConcurrentFibersDoNotInterleave(): void
    {
        [$left, $right] = socketPair();
        $a = str_repeat('a', 2_000_000);
        $b = str_repeat('b', 2_000_000);

        $received = group(static function (Group $group) use ($left, $right, $a, $b) {
            $group->spawn(static fn() => $left->write($a));
            $group->spawn(static fn() => $left->write($b));

            return readExactly($right, 4_000_000);
        });

        Assert::true($received === $a . $b);
    }

    public function readsThatNeverWaitStillLetOtherCallbacksRun(): void
    {
        [$left, $right] = socketPair();
        $left->write(str_repeat('x', 1_000));
        $progress = new \stdClass();
        $progress->reads = 0;
        $progress->readsWhenTicked = null;
        EventLoop::defer(static function () use ($progress) {
            $progress->readsWhenTicked = $progress->reads;
        });

        for (; $progress->reads < 1_000; ++$progress->reads) {
            $right->read(1);
        }

        Assert::true(\is_int($progress->readsWhenTicked) && $progress->readsWhenTicked < 1_000);
    }

    public function throwsUnexpectedEofOnceThePeerHasClosedAndEverythingIsRead(): void
    {
        [$left, $right] = socketPair();
        $left->write('last');
        $left->close();

        Assert::same($right->read(100), 'last');
        Expect::exception(UnexpectedEof::class)->withMessage('The peer has closed the connection.');

        $right->read(100);
    }

    public function throwsReadFailedOnReset(): void
    {
        $server = listen('127.0.0.1', 0);
        \assert($server->address instanceof InetAddress);
        $client = stream_socket_client('tcp://' . $server->address);
        \assert($client !== false);
        $accepted = $server->accept();
        \assert($accepted !== null);
        $socket = socket_import_stream($client);
        \assert($socket !== false);
        // linger 0: close() sends RST instead of FIN
        socket_set_option($socket, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
        socket_close($socket);
        delay(0.01);
        Expect::exception(ReadFailed::class)->withMessage('The connection is broken: reset by the peer or timed out.');

        $accepted->read(100);
    }

    public function throwsSocketClosedAfterClose(): void
    {
        [$left] = socketPair();
        $left->close();
        $left->close();

        Assert::true($left->isClosed);
        Expect::exception(SocketClosed::class)->withMessage('The socket is closed.');

        $left->read(1);
    }

    public function closeEndsAWaitingRead(): void
    {
        [$left, $right] = socketPair();
        Expect::exception(SocketClosed::class)->withMessage('The socket was closed while reading.');

        group(static function (Group $group) use ($left) {
            $group->spawn(static fn() => $left->read(1));
            delay(0.01);
            $left->close();
        });
        unset($right);
    }

    public function rejectsASecondReader(): void
    {
        [$left, $right] = socketPair();
        Expect::exception(\LogicException::class)->withMessage('Another fiber is reading from the socket.');

        group(static function (Group $group) use ($left) {
            $group->daemon(static fn() => $left->read(1));
            delay(0.01);
            $left->read(1);
        });
        unset($right);
    }

    public function cancelledReadTakesNothing(): void
    {
        [$left, $right] = socketPair();

        $cancelled = timeoutOr(0.01, static fn() => $right->read(1), default: 'cancelled');
        $left->write('x');

        Assert::same($cancelled, 'cancelled');
        Assert::same($right->read(1), 'x');
    }

    public function writeCancelledAfterSendingAPartClosesTheSocket(): void
    {
        [$left, $right] = socketPair();

        timeoutOr(0.01, static fn() => $left->write(str_repeat('x', 10_000_000)));

        Assert::true($left->isClosed);
        Assert::false($right->isClosed);
    }

    public function writeCancelledBeforeSendingLeavesTheSocketOpen(): void
    {
        [$left, $right] = socketPair();

        group(static function (Group $group) use ($left, $right) {
            $group->spawn(static fn() => $left->write(str_repeat('a', 10_000_000)));
            delay(0.01);

            // waits for the write above
            timeoutOr(0.01, static fn() => $left->write('b'));

            Assert::false($left->isClosed);
            readExactly($right, 10_000_000);
        });

        $left->write('c');
        Assert::same($right->read(1), 'c');
    }

    public function throwsWriteFailedOnceThePeerIsGone(): void
    {
        [$left, $right] = socketPair();
        $right->close();
        Expect::exception(WriteFailed::class)->withMessageContaining('Broken pipe');

        $left->write('x');
    }

    public function closeWriteSendsTheEndAndKeepsReading(): void
    {
        [$client, $server] = tcpPair();
        $client->write('request');
        $client->closeWrite();

        Assert::same(readExactly($server, 7), 'request');

        try {
            $server->read(1);
            Assert::fail('Expected the end of the stream.');
        } catch (UnexpectedEof) {
        }

        $server->write('response');
        Assert::same(readExactly($client, 8), 'response');
    }

    public function knowsItsAddresses(): void
    {
        [$client, $server] = tcpPair();

        Assert::instanceOf($client->remoteAddress, InetAddress::class);
        Assert::same((string) $client->remoteAddress, (string) $server->localAddress);
        Assert::same((string) $client->localAddress, (string) $server->remoteAddress);
        Assert::same((string) socketPair()[0]->remoteAddress, 'unix:');
    }

    public function knowsIpv6Addresses(): void
    {
        [$client] = tcpPair('::1');

        Assert::string((string) $client->remoteAddress)->startsWith('[::1]:');
    }
}
