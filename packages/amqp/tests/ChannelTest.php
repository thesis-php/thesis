<?php

declare(strict_types=1);

namespace Thesis\Amqp;

use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Amqp\Internal\Protocol\BasicAck;
use Thesis\Amqp\Internal\Protocol\BasicCancel;
use Thesis\Amqp\Internal\Protocol\BasicCancelOk;
use Thesis\Amqp\Internal\Protocol\BasicConsume;
use Thesis\Amqp\Internal\Protocol\BasicConsumeOk;
use Thesis\Amqp\Internal\Protocol\BasicDeliver;
use Thesis\Amqp\Internal\Protocol\BasicGet;
use Thesis\Amqp\Internal\Protocol\BasicGetEmpty;
use Thesis\Amqp\Internal\Protocol\BasicGetOk;
use Thesis\Amqp\Internal\Protocol\BasicNack;
use Thesis\Amqp\Internal\Protocol\BasicPublish;
use Thesis\Amqp\Internal\Protocol\BasicReturn;
use Thesis\Amqp\Internal\Protocol\ChannelClose;
use Thesis\Amqp\Internal\Protocol\ChannelCloseOk;
use Thesis\Amqp\Internal\Protocol\ConfirmSelect;
use Thesis\Amqp\Internal\Protocol\ConfirmSelectOk;
use Thesis\Amqp\Internal\Protocol\ConnectionBlocked;
use Thesis\Amqp\Internal\Protocol\ConnectionClose;
use Thesis\Amqp\Internal\Protocol\ConnectionCloseOk;
use Thesis\Amqp\Internal\Protocol\ConnectionUnblocked;
use Thesis\Amqp\Internal\Protocol\QueueDeclare;
use Thesis\Amqp\Internal\Protocol\QueueDeclareOk;
use Thesis\Amqp\Internal\Protocol\TxCommit;
use Thesis\Amqp\Internal\Protocol\TxCommitOk;
use Thesis\Amqp\Internal\Protocol\TxSelect;
use Thesis\Amqp\Internal\Protocol\TxSelectOk;
use Thesis\Async\Group;
use function Thesis\Amqp\Internal\properties;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Time\ms;

#[Test]
final class ChannelTest
{
    public function declaresAQueue(): void
    {
        self::withChannel(static function (Group $group, Channel $channel, FakePeer $peer, int $id) {
            $declared = $group->spawn(static fn() => $channel->queueDeclare(durable: true));

            [$on, $declare] = $peer->expectOn(QueueDeclare::class);
            Assert::same($on, $id);
            Assert::true($declare->durable);
            $peer->send($id, new QueueDeclareOk('amq.gen-1', 3, 1));

            $queue = $declared->await();
            Assert::same($queue->name, 'amq.gen-1');
            Assert::same($queue->messages, 3);
            Assert::same($queue->consumers, 1);
        });
    }

    public function publishesTheMessageWithItsProperties(): void
    {
        self::withChannel(static function (Group $group, Channel $channel, FakePeer $peer) {
            $message = new Message(
                body: str_repeat('x', 10_000),
                headers: ['attempt' => 2],
                contentType: 'text/plain',
                deliveryMode: DeliveryMode::Persistent,
                correlationId: 'c-1',
            );
            $channel->publish($message, exchange: 'events', routingKey: 'user.created');

            $publish = $peer->expect(BasicPublish::class);
            Assert::same($publish->exchange, 'events');
            Assert::same($publish->routingKey, 'user.created');
            Assert::equals($peer->expectMessage(), $message);
        });
    }

    public function publishWaitsForTheConfirm(): void
    {
        self::withConfirms(static function (Group $group, Channel $channel, FakePeer $peer, int $id) {
            $publish = $group->spawn(static fn() => $channel->publish(new Message('a')));

            $peer->expect(BasicPublish::class);
            $peer->expectMessage();
            delay(ms(10));
            Assert::false($publish->isDone);

            $peer->send($id, new BasicAck(1, false));
            $publish->await();
        });
    }

    public function publishThrowsWhenNacked(): void
    {
        self::withConfirms(static function (Group $group, Channel $channel, FakePeer $peer, int $id) {
            $group->spawn(static function () use ($peer, $id) {
                $peer->expect(BasicPublish::class);
                $peer->expectMessage();
                $peer->send($id, new BasicNack(1, false, false));
            });

            Expect::exception(PublishNacked::class);

            $channel->publish(new Message('a'));
        });
    }

    public function publishThrowsWhenAMandatoryMessageIsReturned(): void
    {
        self::withConfirms(static function (Group $group, Channel $channel, FakePeer $peer, int $id) {
            $group->spawn(static function () use ($peer, $id) {
                $publish = $peer->expect(BasicPublish::class);
                Assert::true($publish->mandatory);
                $message = $peer->expectMessage();
                $peer->sendContent(
                    channel: $id,
                    method: new BasicReturn(ReplyCode::NoRoute->value, 'NO_ROUTE', 'events', 'nowhere'),
                    body: $message->body,
                    properties: properties($message),
                );
                $peer->send($id, new BasicAck(1, false));
            });

            $message = new Message('a', headers: ['k' => 'v']);

            $returned = thrown(static fn() => $channel->publish($message, 'events', 'nowhere', mandatory: true));

            Assert::instanceOf($returned, MessageReturned::class);
            Assert::same($returned->replyCode, ReplyCode::NoRoute);
            Assert::equals($returned->returnedMessage, $message);
        });
    }

    public function mandatoryNeedsConfirms(): void
    {
        self::withChannel(static function (Group $group, Channel $channel) {
            Expect::exception(\LogicException::class);

            $channel->publish(new Message('a'), mandatory: true);
        });
    }

    public function publishBatchReportsTheNackedMessages(): void
    {
        self::withConfirms(static function (Group $group, Channel $channel, FakePeer $peer, int $id) {
            $publishes = [new Publish(new Message('1')), new Publish(new Message('2')), new Publish(new Message('3'))];
            $group->spawn(static function () use ($peer, $id) {
                for ($i = 0; $i < 3; ++$i) {
                    $peer->expect(BasicPublish::class);
                    $peer->expectMessage();
                }

                $peer->send($id, new BasicNack(2, false, false));
                $peer->send($id, new BasicAck(3, true));
            });

            $failed = thrown(static fn() => $channel->publishBatch($publishes));

            Assert::instanceOf($failed, PublishBatchFailed::class);
            Assert::same($failed->nacked, [$publishes[1]]);
            Assert::same($failed->returned, []);
        });
    }

    public function consumesUntilCancelled(): void
    {
        self::withChannel(static function (Group $group, Channel $channel, FakePeer $peer, int $id) {
            $group->spawn(static function () use ($peer, $id) {
                $consume = $peer->expect(BasicConsume::class);
                $peer->send($id, new BasicConsumeOk($consume->consumerTag));

                for ($tag = 1; $tag <= 2; ++$tag) {
                    $peer->sendContent($id, new BasicDeliver($consume->consumerTag, $tag, false, '', 'q'), "m{$tag}");
                }

                $ack = $peer->expect(BasicAck::class);
                Assert::same($ack->deliveryTag, 2);
                Assert::true($ack->multiple);

                $cancel = $peer->expect(BasicCancel::class);
                $peer->send($id, new BasicCancelOk($cancel->consumerTag));
            });

            $consumer = $channel->consume('q');
            $bodies = [];

            foreach ($consumer as $delivery) {
                $bodies[] = $delivery->message->body;

                if (\count($bodies) === 2) {
                    $delivery->ack(multiple: true);
                    $consumer->cancel();
                }
            }

            Assert::same($bodies, ['m1', 'm2']);
        });
    }

    public function dropsAnAckThatAMultipleAckCovers(): void
    {
        self::withChannel(static function (Group $group, Channel $channel, FakePeer $peer, int $id) {
            $broker = $group->spawn(static function () use ($peer, $id) {
                $consume = $peer->expect(BasicConsume::class);
                $peer->send($id, new BasicConsumeOk($consume->consumerTag));

                for ($tag = 1; $tag <= 3; ++$tag) {
                    $peer->sendContent($id, new BasicDeliver($consume->consumerTag, $tag, false, '', 'q'), "m{$tag}");
                }

                $ack = $peer->expect(BasicAck::class);
                Assert::same($ack->deliveryTag, 3);
                $cancel = $peer->expect(BasicCancel::class);
                $peer->send($id, new BasicCancelOk($cancel->consumerTag));
            });

            $consumer = $channel->consume('q');
            $deliveries = [];

            foreach ($consumer as $delivery) {
                $deliveries[] = $delivery;

                if (\count($deliveries) === 3) {
                    $delivery->ack(multiple: true);
                    $deliveries[0]->ack();
                    $consumer->cancel();
                }
            }

            $broker->await();
        });
    }

    public function publishBatchReportsANackThatComesWhileTheBatchIsWritten(): void
    {
        self::withConfirms(static function (Group $group, Channel $channel, FakePeer $peer, int $id) {
            $body = str_repeat('x', 1_048_576);
            $publishes = array_map(static fn() => new Publish(new Message($body)), range(1, 16));
            $group->spawn(static function () use ($peer, $id) {
                // The rest of the batch waits in the socket meanwhile.
                $peer->expect(BasicPublish::class);
                $peer->expectMessage();
                $peer->send($id, new BasicNack(1, false, false));

                for ($i = 2; $i <= 16; ++$i) {
                    $peer->expect(BasicPublish::class);
                    $peer->expectMessage();
                }

                $peer->send($id, new BasicAck(16, true));
            });

            $failed = thrown(static fn() => $channel->publishBatch($publishes));

            Assert::instanceOf($failed, PublishBatchFailed::class);
            Assert::same($failed->nacked, [$publishes[0]]);
        });
    }

    public function consumerThrowsWhenTheServerCancelsIt(): void
    {
        self::withChannel(static function (Group $group, Channel $channel, FakePeer $peer, int $id) {
            $group->spawn(static function () use ($peer, $id) {
                $consume = $peer->expect(BasicConsume::class);
                $peer->send($id, new BasicConsumeOk($consume->consumerTag));
                $peer->send($id, new BasicCancel($consume->consumerTag, false));
            });

            Expect::exception(ConsumerCancelled::class);

            foreach ($channel->consume('q') as $_) {
                Assert::fail('Nothing is delivered.');
            }
        });
    }

    public function getsAMessageOrNull(): void
    {
        self::withChannel(static function (Group $group, Channel $channel, FakePeer $peer, int $id) {
            $group->spawn(static function () use ($peer, $id) {
                $peer->expect(BasicGet::class);
                $peer->send($id, new BasicGetEmpty());
                $peer->expect(BasicGet::class);
                $peer->sendContent($id, new BasicGetOk(7, true, 'events', 'key', 0), 'body');
            });

            Assert::null($channel->get('q'));

            $delivery = $channel->get('q');
            Assert::notNull($delivery);
            Assert::same($delivery->message->body, 'body');
            Assert::same($delivery->deliveryTag, 7);
            Assert::true($delivery->redelivered);
            Assert::null($delivery->consumerTag);
        });
    }

    public function throwsWhatTheServerClosesTheChannelWith(): void
    {
        self::withChannel(static function (Group $group, Channel $channel, FakePeer $peer, int $id) {
            $broker = $group->spawn(static function () use ($peer, $id) {
                $peer->expect(QueueDeclare::class);
                $peer->send($id, new ChannelClose(ReplyCode::NotFound->value, 'NOT_FOUND - no queue', 50, 10));
                $peer->expect(ChannelCloseOk::class);
            });

            $failure = thrown(static fn() => $channel->queueDeclare('missing', passive: true));

            Assert::instanceOf($failure, ChannelClosed::class);
            Assert::same($failure->replyCode, ReplyCode::NotFound);
            Assert::true($channel->isClosed);
            $broker->await();
        });
    }

    public function publishWaitsWhileTheServerBlocksTheConnection(): void
    {
        self::withChannel(static function (Group $group, Channel $channel, FakePeer $peer) {
            $peer->send(0, new ConnectionBlocked('low on memory'));
            delay(ms(10));
            $publish = $group->spawn(static fn() => $channel->publish(new Message('a')));

            delay(ms(10));
            Assert::false($publish->isDone);

            $peer->send(0, new ConnectionUnblocked());
            $publish->await();
            $peer->expect(BasicPublish::class);
        });
    }

    public function aPublishWaitingForTheMutexThrowsWhenTheChannelCloses(): void
    {
        self::withConfirms(static function (Group $group, Channel $channel, FakePeer $peer, int $id) {
            // Larger than the socket buffers: the first publish holds the mutex while its write waits.
            $first = $group->spawn(static fn() => thrown(static fn() => $channel->publish(
                new Message(str_repeat('x', 16_777_216)),
            )));
            delay(ms(10));
            $second = $group->spawn(static fn() => thrown(static fn() => $channel->publish(new Message('second'))));
            delay(ms(10));

            $peer->send($id, new ChannelClose(ReplyCode::NotFound->value, 'NOT_FOUND', 60, 40));
            $peer->expect(BasicPublish::class);
            $peer->expectMessage();
            $peer->expect(ChannelCloseOk::class);

            foreach ([$first, $second] as $publish) {
                $failure = $publish->await();
                Assert::instanceOf($failure, ChannelClosed::class);
                Assert::same($failure->replyCode, ReplyCode::NotFound);
            }
        });
    }

    public function aPublishWaitingWhileBlockedThrowsWhenTheChannelCloses(): void
    {
        self::withChannel(static function (Group $group, Channel $channel, FakePeer $peer, int $id) {
            $peer->send(0, new ConnectionBlocked('low on memory'));
            delay(ms(10));
            $publish = $group->spawn(static fn() => thrown(static fn() => $channel->publish(new Message('a'))));
            delay(ms(10));

            $peer->send($id, new ChannelClose(ReplyCode::NotFound->value, 'NOT_FOUND', 60, 40));
            $peer->expect(ChannelCloseOk::class);
            $peer->send(0, new ConnectionUnblocked());

            Assert::instanceOf($publish->await(), ChannelClosed::class);
        });
    }

    public function aPublishWaitingWhileBlockedThrowsWhenTheConnectionCloses(): void
    {
        self::withChannel(static function (Group $group, Channel $channel, FakePeer $peer) {
            $peer->send(0, new ConnectionBlocked('low on memory'));
            delay(ms(10));
            $publish = $group->spawn(static fn() => thrown(static fn() => $channel->publish(new Message('a'))));
            delay(ms(10));

            $peer->send(0, new ConnectionClose(ReplyCode::ConnectionForced->value, 'CONNECTION_FORCED', 0, 0));
            $peer->expect(ConnectionCloseOk::class);

            Assert::instanceOf($publish->await(), ConnectionClosed::class);
        });
    }

    public function reusesTheIdOfAClosedChannel(): void
    {
        $server = FakeServer::listen();

        group(static function (Group $group) use ($server) {
            $peer = $group->spawn(static function () use ($server) {
                $peer = $server->accept();
                $peer->handshake(channelMax: 1);

                return $peer;
            });
            $client = new Client($server->config());
            $client->run($group);
            $peer = $peer->await();
            [$channel] = openChannel($group, $client, $peer);

            $group->spawn(static function () use ($peer) {
                $peer->expect(ChannelClose::class);
                $peer->send(1, new ChannelCloseOk());
            });
            $channel->close();

            [$channel, $id] = openChannel($group, $client, $peer);
            Assert::same($id, 1);
            Assert::same($channel->id, 1);
            $peer->socket->close();
        });
    }

    public function commitsATransaction(): void
    {
        self::withChannel(static function (Group $group, Channel $channel, FakePeer $peer, int $id) {
            $group->spawn(static function () use ($peer, $id) {
                $peer->expect(TxSelect::class);
                $peer->send($id, new TxSelectOk());
                $peer->expect(BasicPublish::class);
                $peer->expectMessage();
                $peer->expect(TxCommit::class);
                $peer->send($id, new TxCommitOk());
            });

            $result = $channel->transactional(static function (Channel $channel) {
                $channel->publish(new Message('a'));

                return 'done';
            });

            Assert::same($result, 'done');
        });
    }

    /**
     * @param callable(Group, Channel, FakePeer, int<1, 65535>): void $test
     */
    private static function withChannel(callable $test): void
    {
        $server = FakeServer::listen();

        group(static function (Group $group) use ($server, $test) {
            [$client, $peer] = connect($group, $server);
            [$channel, $id] = openChannel($group, $client, $peer);

            try {
                $test($group, $channel, $peer, $id);
            } finally {
                $peer->socket->close();
            }
        });
    }

    /**
     * @param callable(Group, Channel, FakePeer, int<1, 65535>): void $test
     */
    private static function withConfirms(callable $test): void
    {
        self::withChannel(static function (Group $group, Channel $channel, FakePeer $peer, int $id) use ($test) {
            $group->spawn(static function () use ($peer, $id) {
                $peer->expect(ConfirmSelect::class);
                $peer->send($id, new ConfirmSelectOk());
            });
            $channel->confirmSelect();

            $test($group, $channel, $peer, $id);
        });
    }
}
