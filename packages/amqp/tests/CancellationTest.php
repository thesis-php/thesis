<?php

declare(strict_types=1);

namespace Thesis\Amqp;

use Testo\Assert;
use Testo\Test;
use Thesis\Amqp\Internal\Protocol\BasicAck;
use Thesis\Amqp\Internal\Protocol\BasicConsume;
use Thesis\Amqp\Internal\Protocol\BasicConsumeOk;
use Thesis\Amqp\Internal\Protocol\BasicDeliver;
use Thesis\Amqp\Internal\Protocol\BasicGet;
use Thesis\Amqp\Internal\Protocol\BasicGetOk;
use Thesis\Amqp\Internal\Protocol\BasicPublish;
use Thesis\Amqp\Internal\Protocol\ConfirmSelect;
use Thesis\Amqp\Internal\Protocol\ConfirmSelectOk;
use Thesis\Amqp\Internal\Protocol\ExchangeDeclare;
use Thesis\Amqp\Internal\Protocol\ExchangeDeclareOk;
use Thesis\Amqp\Internal\Protocol\QueueDeclare;
use Thesis\Amqp\Internal\Protocol\QueueDeclareOk;
use Thesis\Async\Group;
use Thesis\Async\TimedOut;
use function Thesis\Async\delay;
use function Thesis\Async\group;
use function Thesis\Async\timeout;
use function Thesis\Time\ms;

/**
 * A caller cancelled in the middle of a call must leave the channel as the broker sees it.
 */
#[Test]
final class CancellationTest
{
    public function theNextCallDoesNotTakeTheReplyOfACancelledOne(): void
    {
        self::withChannel(static function (Group $group, Channel $channel, FakePeer $peer, int $id) {
            $group->spawn(static function () use ($peer, $id) {
                $peer->expect(QueueDeclare::class);
                delay(ms(50));
                $peer->send($id, new QueueDeclareOk('first', 0, 0));
                $peer->expect(ExchangeDeclare::class);
                $peer->send($id, new ExchangeDeclareOk());
            });

            try {
                timeout(ms(10), static fn() => $channel->queueDeclare('first'));
                Assert::fail('The declaration must time out.');
            } catch (TimedOut) {
            }

            $channel->exchangeDeclare('second');
            Assert::false($channel->isClosed);
        });
    }

    /**
     * The call runs to its reply under shield(), and nothing waits after it: the deadline cannot discard
     * a consumer that the broker has started.
     */
    public function aConsumeCutByATimeoutStillReturnsItsConsumer(): void
    {
        self::withChannel(static function (Group $group, Channel $channel, FakePeer $peer, int $id) {
            $broker = $group->spawn(static function () use ($peer, $id) {
                $consume = $peer->expect(BasicConsume::class);
                delay(ms(50));
                $peer->send($id, new BasicConsumeOk($consume->consumerTag));

                return $consume->consumerTag;
            });

            $consumer = timeout(ms(10), static fn() => $channel->consume('q'));
            $peer->sendContent($id, new BasicDeliver($broker->await(), 1, false, '', 'q'), 'delivered');

            foreach ($consumer as $delivery) {
                Assert::same($delivery->message->body, 'delivered');
                break;
            }
        });
    }

    public function aGetCutByATimeoutStillReturnsItsDelivery(): void
    {
        self::withChannel(static function (Group $group, Channel $channel, FakePeer $peer, int $id) {
            $broker = $group->spawn(static function () use ($peer, $id) {
                $peer->expect(BasicGet::class);
                delay(ms(50));
                $peer->sendContent($id, new BasicGetOk(1, false, '', 'q', 0), 'got');
            });

            $delivery = timeout(ms(10), static fn() => $channel->get('q'));
            $broker->await();

            Assert::same($delivery?->message->body, 'got');
        });
    }

    public function aPublishCancelledWhileWaitingForItsConfirmDoesNotShiftTheNextOne(): void
    {
        self::withChannel(static function (Group $group, Channel $channel, FakePeer $peer, int $id) {
            $group->spawn(static function () use ($peer, $id) {
                $peer->expect(ConfirmSelect::class);
                $peer->send($id, new ConfirmSelectOk());

                for ($tag = 1; $tag <= 2; ++$tag) {
                    $peer->expect(BasicPublish::class);
                    $peer->expectMessage();
                }

                $peer->send($id, new BasicAck(1, false));
                $peer->send($id, new BasicAck(2, false));
            });
            $channel->confirmSelect();

            try {
                timeout(ms(10), static fn() => $channel->publish(new Message('first')));
                Assert::fail('The confirm must time out.');
            } catch (TimedOut) {
            }

            $channel->publish(new Message('second'));
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
}
