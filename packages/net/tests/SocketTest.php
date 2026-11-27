<?php

declare(strict_types=1);

namespace Thesis\Net;

use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;
use Thesis\Async\Group;
use Thesis\Io\ConcurrentRead;
use Thesis\Io\ReadFailed;
use Thesis\Io\UnexpectedEof;
use Thesis\Io\WriteFailed;
use function Thesis\Async\checkpoint;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\timeout;
use function Thesis\Async\timeoutOr;
use function Thesis\Async\Trace\watchdog;
use function Thesis\Time\ms;
use function Thesis\Time\sec;
use const Thesis\Time\ZERO;

#[Test]
final class SocketTest
{
    public function triesAReadWithoutWaitingForData(): void
    {
        [$left, $right] = socketPair();

        Assert::null($right->tryRead(1));
        $left->write('xy');
        Assert::same($right->tryRead(1), 'x');
        Assert::same($right->read(1), 'y');
        $left->close();

        try {
            $right->tryRead(1);
            Assert::fail('Expected EOF after the peer closed the socket.');
        } catch (UnexpectedEof) {
            $right->close();
        }
    }

    public function immediateWritesStopAtThePacingLimitUntilTheNextTick(): void
    {
        [$socket, $peer] = socketPair();
        for ($i = 0; $i < 16; ++$i) {
            Assert::same($socket->tryWrite('x'), 1);
        }

        Assert::same($socket->tryWrite('y'), 0);
        checkpoint();
        Assert::same($socket->tryWrite('z'), 1);
        Assert::same(readExactly($peer, 17), str_repeat('x', 16) . 'z');
    }

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
        delay(ms(10));

        Assert::true(\strlen($server->read(65_536) ?? '') > 8192);
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

    #[DataSet([false], 'another write')]
    #[DataSet([true], 'half-close')]
    public function aPacedWriteKeepsItsPlace(bool $halfClose): void
    {
        [$left, $right] = socketPair();
        timeout(sec(1), static function () use ($left, $right, $halfClose) {
            group(static function (Group $group) use ($left, $halfClose) {
                $group->spawn(static function () use ($left, $halfClose) {
                    checkpoint();

                    if ($halfClose) {
                        $left->closeWrite();
                    } else {
                        $left->write('B');
                    }
                });
                $group->spawn(static function () use ($left) {
                    for ($i = 0; $i < 16; ++$i) {
                        $left->write('.');
                    }

                    $left->write('A');
                });
            });

            $expected = str_repeat('.', 16) . ($halfClose ? 'A' : 'AB');
            Assert::same(readExactly($right, \strlen($expected)), $expected);
        });
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
        $left->write(str_repeat('x', 1000));
        $progress = new \stdClass();
        $progress->reads = 0;
        $progress->readsWhenTicked = null;
        // A callback of the loop: it runs if the loop has ticked.
        watchdog(ZERO, static function () use ($progress) {
            $progress->readsWhenTicked = $progress->reads;
        });

        for (; $progress->reads < 1000; ++$progress->reads) {
            $right->read(1);
        }

        Assert::true(\is_int($progress->readsWhenTicked) && $progress->readsWhenTicked < 1000);
    }

    public function returnsNullOnceThePeerHasClosedAndEverythingIsRead(): void
    {
        [$left, $right] = socketPair();
        $left->write('last');
        $left->close();

        Assert::same($right->read(100), 'last');
        Assert::null($right->read(100));
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
        delay(ms(10));
        Expect::exception(ReadFailed::class)->withMessage('The connection is broken: reset by the peer or timed out.');
        @trigger_error('unrelated', E_USER_WARNING);

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

    public function rejectsEveryOperationAfterClose(): void
    {
        [$left] = socketPair();
        $left->close();

        $failures = [
            thrown(static fn() => $left->write('x')),
            thrown($left->closeWrite(...)),
            thrown(static fn() => $left->enableTls(new ClientTls(peerName: 'server.test'))),
        ];

        foreach ($failures as $failure) {
            Assert::instanceOf($failure, SocketClosed::class);
            Assert::same($failure->getMessage(), 'The socket is closed.');
        }
    }

    public function closeEndsEveryWaitingWrite(): void
    {
        [$left, $right] = socketPair();

        $bytes = str_repeat('a', 10_000_000);

        [$first, $second] = group(static function (Group $group) use ($left, $bytes) {
            $first = $group->spawn(static fn() => thrown(static fn() => $left->write($bytes)));
            // waits for the write above
            $second = $group->spawn(static fn() => thrown(static fn() => $left->write('b')));
            delay(ms(10));
            $left->close();

            return [$first->await(), $second->await()];
        });

        Assert::instanceOf($first, SocketClosed::class);
        Assert::instanceOf($second, SocketClosed::class);
        Assert::same($second->getMessage(), 'The socket was closed while writing.');
        Assert::false($right->isClosed);
    }

    public function closeEndsAWaitingRead(): void
    {
        [$left, $right] = socketPair();
        Expect::exception(SocketClosed::class)->withMessage('The socket was closed while reading.');

        group(static function (Group $group) use ($left) {
            $group->spawn(static fn() => $left->read(1));
            delay(ms(10));
            $left->close();
        });
        unset($right);
    }

    public function rejectsASecondReader(): void
    {
        [$left, $right] = socketPair();
        Expect::exception(ConcurrentRead::class)->withMessage('A read is already in progress on the socket.');

        group(static function (Group $group) use ($left) {
            $group->daemon(static fn() => $left->read(1));
            delay(ms(10));
            $left->read(1);
        });
        unset($right);
    }

    public function cancelledReadTakesNothing(): void
    {
        [$left, $right] = socketPair();

        $cancelled = timeoutOr(ms(10), static fn() => $right->read(1), default: 'cancelled');
        $left->write('x');

        Assert::same($cancelled, 'cancelled');
        Assert::same($right->read(1), 'x');
    }

    public function secondReaderIsAnErrorEvenIfBytesHaveArrivedForTheFirst(): void
    {
        [$left, $right] = socketPair();
        Expect::exception(ConcurrentRead::class)->withMessage('A read is already in progress on the socket.');

        // without the error, the first reader would wait forever for the bytes the second has taken
        timeout(sec(1), static fn() => group(static function (Group $group) use ($left, $right) {
            $group->spawn(static fn() => $right->read(100));
            delay(ms(1));
            $left->write('for the first');
            $right->read(100);
        }));
    }

    public function writesThatNeverWaitStillLetOtherCallbacksRun(): void
    {
        [$left, $right] = socketPair();
        $progress = new \stdClass();
        $progress->writes = 0;
        $progress->writesWhenTicked = null;
        // A callback of the loop: it runs if the loop has ticked.
        watchdog(ZERO, static function () use ($progress) {
            $progress->writesWhenTicked = $progress->writes;
        });

        // few enough to fit into the buffer of the socket: each small write costs it far more than a byte
        for (; $progress->writes < 100; ++$progress->writes) {
            $left->write('x');
        }

        Assert::true(\is_int($progress->writesWhenTicked) && $progress->writesWhenTicked < 100);
        unset($right);
    }

    public function readDoesNotAllocateTheWholeMaxUpFront(): void
    {
        [$left, $right] = socketPair();
        $left->write('x');
        memory_reset_peak_usage();
        $before = memory_get_peak_usage();

        $right->read(512 * 1_048_576);

        Assert::true(memory_get_peak_usage() - $before < 4 * 1_048_576);
    }

    public function writeDoesNotSlipIntoRoomFreedForAWaitingWrite(): void
    {
        [$left, $right] = socketPair();
        $a = str_repeat('a', 4_000_000);

        $received = group(static function (Group $group) use ($left, $right, $a) {
            $group->spawn(static fn() => $left->write($a));
            delay(ms(1));
            // frees room while the first write still waits, then a second write starts before it resumes
            $received = readExactly($right, 1000);
            Assert::same($left->tryWrite('x'), 0);
            $group->spawn(static fn() => $left->write('bcde'));
            delay(ms(1));

            return $received . readExactly($right, 4_000_004 - 1000);
        });

        Assert::true($received === $a . 'bcde');
    }

    public function writeCancelledAfterSendingAPartClosesTheSocket(): void
    {
        [$left, $right] = socketPair();

        timeoutOr(ms(10), static fn() => $left->write(str_repeat('x', 10_000_000)));

        Assert::true($left->isClosed);
        Assert::false($right->isClosed);
    }

    public function writeCancelledBeforeSendingLeavesTheSocketOpen(): void
    {
        [$left, $right] = socketPair();

        group(static function (Group $group) use ($left, $right) {
            $group->spawn(static fn() => $left->write(str_repeat('a', 10_000_000)));
            delay(ms(10));

            // waits for the write above
            timeoutOr(ms(10), static fn() => $left->write('b'));

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

    public function closeWriteWaitsForTheWriteInProgress(): void
    {
        [$left, $right] = socketPair();
        $bytes = str_repeat('a', 4_000_000);

        $received = group(static function (Group $group) use ($left, $right, $bytes) {
            $group->spawn(static fn() => $left->write($bytes));
            delay(ms(1));
            $group->spawn($left->closeWrite(...));

            return readExactly($right, 4_000_000);
        });

        Assert::true($received === $bytes);
        Assert::null($right->read(1));
    }

    public function closeWriteSendsTheEndAndKeepsReading(): void
    {
        [$client, $server] = tcpPair();
        $client->write('request');
        $client->closeWrite();

        Assert::same(readExactly($server, 7), 'request');

        Assert::null($server->read(1));
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
