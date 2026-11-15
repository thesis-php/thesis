<?php

declare(strict_types=1);

namespace Thesis\Amqp;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Amqp\Internal\Frames;
use Thesis\Amqp\Internal\Protocol\ConnectionClose;
use Thesis\Amqp\Internal\Protocol\ConnectionCloseOk;
use Thesis\Amqp\Internal\Protocol\ConnectionStart;
use Thesis\Async\Group;
use Thesis\Io\IoException;
use Thesis\Testo\VirtualTime;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Time\ms;
use function Thesis\Time\sec;

#[Test]
final class ClientTest
{
    public function sendsTheCredentialsAndTheCapabilities(): void
    {
        $server = FakeServer::listen();

        group(static function (Group $group) use ($server) {
            $startOk = $group->spawn(static fn() => $server->accept()->handshake());
            $client = new Client($server->config());
            $client->run($group);

            $startOk = $startOk->await();
            Assert::same($startOk->mechanism, 'PLAIN');
            Assert::same($startOk->response, "\0guest\0guest");
            Assert::array($startOk->clientProperties['capabilities'] ?? null);
            Assert::same($startOk->clientProperties['capabilities']['publisher_confirms'] ?? null, true);
            Assert::same($startOk->clientProperties['capabilities']['consumer_cancel_notify'] ?? null, true);
        });
    }

    public function closesGracefully(): void
    {
        $server = FakeServer::listen();

        group(static function (Group $group) use ($server) {
            [$client, $peer] = connect($group, $server);
            closeClient($group, $client, $peer);

            Expect::exception(ConnectionClosed::class)->withMessage('The client is closed.');

            $client->channel();
        });
    }

    public function failsWhenTheServerSupportsNoneOfTheMechanisms(): void
    {
        $server = FakeServer::listen();

        group(static function (Group $group) use ($server) {
            $group->spawn(static function () use ($server) {
                $peer = $server->accept();
                $peer->socket->read(8);
                $peer->send(0, new ConnectionStart(0, 9, [], 'EXTERNAL', 'en_US'));
            });

            Expect::exception(ConnectionFailed::class);

            new Client($server->config())->run($group);
        });
    }

    public function throwsWhatTheServerClosesTheConnectionWith(): void
    {
        $server = FakeServer::listen();

        group(static function (Group $group) use ($server) {
            [$client, $peer] = connect($group, $server);
            $peer->send(0, new ConnectionClose(ReplyCode::ConnectionForced->value, 'CONNECTION_FORCED', 0, 0));
            $peer->expect(ConnectionCloseOk::class);
            delay(ms(10));

            try {
                $client->channel();
                Assert::fail('The channel must not open.');
            } catch (ConnectionClosed $exception) {
                Assert::same($exception->replyCode, ReplyCode::ConnectionForced);
            }
        });
    }

    #[VirtualTime]
    public function closesTheConnectionAfterTwoSilentHeartbeatIntervals(): void
    {
        $server = FakeServer::listen();

        group(static function (Group $group) use ($server) {
            $peer = $group->spawn(static function () use ($server) {
                $peer = $server->accept();
                $peer->handshake(heartbeat: 1);

                return $peer;
            });
            $client = new Client($server->config(heartbeat: sec(1)));
            $client->run($group);
            $peer = $peer->await();

            // The client sends its heartbeats and, hearing nothing for 2 seconds, closes the socket.
            try {
                $peer->frame();
                Assert::fail('The client must send nothing but heartbeats.');
            } catch (IoException) {
            }

            Expect
                ::exception(ConnectionClosed::class)
                ->withMessage('The server has sent nothing for 2 seconds: heartbeats are missed.')
            ;

            $client->channel();
        });
    }

    #[VirtualTime]
    public function staysConnectedWhileTheServerSendsHeartbeats(): void
    {
        $server = FakeServer::listen();

        group(static function (Group $group) use ($server) {
            $peer = $group->spawn(static function () use ($server) {
                $peer = $server->accept();
                $peer->handshake(heartbeat: 1);

                return $peer;
            });
            $client = new Client($server->config(heartbeat: sec(1)));
            $client->run($group);
            $peer = $peer->await();

            for ($i = 0; $i < 6; ++$i) {
                delay(ms(500));
                $peer->heartbeat();
            }

            [$channel] = openChannel($group, $client, $peer);
            Assert::same($channel->id, 1);
            closeClient($group, $client, $peer);
        });
    }

    public function reconnectsAfterTheConnectionIsLost(): void
    {
        $server = FakeServer::listen();

        group(static function (Group $group) use ($server) {
            [$client, $peer] = connect($group, $server, $server->config(reconnect: true));
            $peer->socket->close();

            $peer = $server->accept();
            $peer->handshake();
            [$channel] = openChannel($group, $client, $peer);

            Assert::same($channel->id, 1);
            closeClient($group, $client, $peer);
        });
    }

    public function throwsWhenUsedBeforeRun(): void
    {
        Expect::exception(\LogicException::class)->withMessage('The client is not running: call run() first.');

        new Client(new Config())->channel();
    }

    public function rejectsAServerOfAnotherProtocol(): void
    {
        $server = FakeServer::listen();

        group(static function (Group $group) use ($server) {
            $group->spawn(static function () use ($server) {
                $peer = $server->accept();
                $peer->socket->read(8);
                $peer->socket->write(Frames::PROTOCOL_HEADER);
            });

            Expect::exception(ConnectionFailed::class);

            new Client($server->config())->run($group);
        });
    }
}
