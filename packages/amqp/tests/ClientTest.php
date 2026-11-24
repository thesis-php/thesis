<?php

declare(strict_types=1);

namespace Thesis\Amqp;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Amqp\Internal\Frames;
use Thesis\Amqp\Internal\Protocol\BasicAck;
use Thesis\Amqp\Internal\Protocol\BasicPublish;
use Thesis\Amqp\Internal\Protocol\ConfirmSelect;
use Thesis\Amqp\Internal\Protocol\ConfirmSelectOk;
use Thesis\Amqp\Internal\Protocol\ConnectionClose;
use Thesis\Amqp\Internal\Protocol\ConnectionCloseOk;
use Thesis\Amqp\Internal\Protocol\ConnectionStart;
use Thesis\Async\Deferred;
use Thesis\Async\Group;
use Thesis\Io\IoException;
use Thesis\TestoPlugin\VirtualTime;
use function Thesis\Async\checkpoint;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\timeout;
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
            $client->start($group);

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
            stopClient($group, $client, $peer);

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

            new Client($server->config())->start($group);
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

            $failure = thrown($client->channel(...));

            Assert::instanceOf($failure, ConnectionClosed::class);
            Assert::same($failure->replyCode, ReplyCode::ConnectionForced);
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
            $client->start($group);
            $peer = $peer->await();

            // The client sends nothing but its heartbeats and, hearing nothing for 2 seconds, closes the socket.
            Assert::instanceOf(thrown($peer->frame(...)), IoException::class);

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
            $client->start($group);
            $peer = $peer->await();

            for ($i = 0; $i < 6; ++$i) {
                delay(ms(500));
                $peer->heartbeat();
            }

            [$channel] = openChannel($group, $client, $peer);
            Assert::same($channel->id, 1);
            stopClient($group, $client, $peer);
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
            stopClient($group, $client, $peer);
        });
    }

    public function roundsAHeartbeatUnderASecondUp(): void
    {
        $server = FakeServer::listen();

        group(static function (Group $group) use ($server) {
            $tuneOk = $group->spawn(static function () use ($server) {
                $peer = $server->accept();
                $peer->start();

                return $peer->tune(heartbeat: 60);
            });
            new Client($server->config(heartbeat: ms(300)))->start($group);

            Assert::same($tuneOk->await()->heartbeat, 1);
        });
    }

    public function throwsWhenEveryChannelIsOpen(): void
    {
        $server = FakeServer::listen();

        group(static function (Group $group) use ($server) {
            $peer = $group->spawn(static function () use ($server) {
                $peer = $server->accept();
                $peer->handshake(channelMax: 1);

                return $peer;
            });
            $client = new Client($server->config(reconnect: true));
            $client->start($group);
            openChannel($group, $client, $peer->await());

            Expect
                ::exception(ChannelClosed::class)
                ->withMessage('No channel can open: all 1 channels of the connection are open.')
            ;

            $client->channel();
        });
    }

    public function stopDuringAReconnectLeavesNoConnection(): void
    {
        $server = FakeServer::listen();

        group(static function (Group $group) use ($server) {
            [$client, $peer] = connect($group, $server, $server->config(reconnect: true));
            $peer->socket->close();

            $peer = $server->accept();
            $peer->start();
            $closed = $group->spawn($client->stop(...));
            delay(ms(10));
            Assert::false($closed->isDone);
            $peer->tune();
            $closed->await();

            // The client closes the connection it has opened during stop().
            Assert::instanceOf(thrown($peer->frame(...)), IoException::class);

            Expect::exception(ConnectionClosed::class)->withMessage('The client is closed.');

            $client->channel();
        });
    }

    public function stopWaitsForTheConfirmsOfThePublishesInFlight(): void
    {
        $server = FakeServer::listen();

        group(static function (Group $group) use ($server) {
            [$client, $peer] = connect($group, $server);
            [$channel, $id] = openChannel($group, $client, $peer);
            $group->spawn(static function () use ($peer, $id) {
                $peer->expect(ConfirmSelect::class);
                $peer->send($id, new ConfirmSelectOk());
            });
            $channel->confirmSelect();
            $publish = $group->spawn(static fn() => $channel->publish(new Message('in flight')));
            $peer->expect(BasicPublish::class);
            $peer->expectMessage();

            $close = $group->spawn($client->stop(...));
            delay(ms(10));
            Assert::false($close->isDone);

            $peer->send($id, new BasicAck(1, false));
            $publish->await();
            $peer->acceptClose();
            $close->await();
        });
    }

    public function channelWaitsForTheConnectionWhileReconnecting(): void
    {
        $server = FakeServer::listen();

        group(static function (Group $group) use ($server) {
            [$client, $peer] = connect($group, $server, $server->config(reconnect: true));
            $peer->socket->close();
            $channel = $group->spawn($client->channel(...));
            delay(ms(10));

            $peer = $server->accept();
            $peer->handshake();
            $peer->openChannel();

            Assert::same($channel->await()->id, 1);
            stopClient($group, $client, $peer);
        });
    }

    public function throwsWhenUsedBeforeStart(): void
    {
        Expect::exception(\LogicException::class)->withMessage('The client is not running: call start() first.');

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

            new Client($server->config())->start($group);
        });
    }

    public function failedFirstHandshakeAlsoFailsAWaitingChannel(): void
    {
        timeout(sec(3), static fn() => group(static function (Group $group) {
            $server = FakeServer::listen();
            $client = new Client($server->config());
            $startup = $group->spawn(static fn() => thrown(static fn() => $client->start($group)));
            $peer = $server->accept();
            $waiting = $group->spawn(static fn() => thrown(static fn() => timeout(ms(500), $client->channel(...))));
            checkpoint();
            $peer->socket->close();
            Assert::instanceOf($startup->await(), ConnectionFailed::class);
            Assert::instanceOf($waiting->await(), ConnectionFailed::class);
        }));
    }

    public function channelWaitDuringReconnectEndsWithTheOwnerGroup(): void
    {
        timeout(sec(3), static fn() => group(static function (Group $outer) {
            /** @var Deferred<Client> $ready */
            $ready = new Deferred();
            /** @var Deferred<null> $exit */
            $exit = new Deferred();
            $owner = $outer->spawn(static fn() => group(static function (Group $inner) use ($ready, $exit) {
                $server = FakeServer::listen();
                [$client, $peer] = connect($inner, $server, $server->config(reconnect: true));
                $peer->socket->close();
                delay(ms(10));
                $ready->complete($client);
                $exit->await();
            }));
            $client = $ready->await();
            $waiter = $outer->spawn(static fn() => thrown(static fn() => timeout(ms(500), $client->channel(...))));
            checkpoint();
            $exit->complete();
            $owner->await();
            Assert::instanceOf($waiter->await(), ConnectionClosed::class);
        }));
    }
}
